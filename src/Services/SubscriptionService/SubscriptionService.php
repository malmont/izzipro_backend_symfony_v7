<?php

namespace App\Services\SubscriptionService;

use App\Dto\SubscriptionOutputDto;
use App\Dto\SubscriptionPlanOutputDto;
use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\Order;
use App\Entity\Subscription;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Repository\SubscriptionPlanRepository;
use App\Repository\SubscriptionRepository;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\OrderService\TaxEngine;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;

/**
 * Abonnements de la boutique réglable (09/10/2026) : formules d'un produit, souscription (abonnement Stripe Billing
 * sur le compte connecté, première facture payée par le clientSecret renvoyé), annulation à la fin de la période ou
 * immédiate, reprise, pause, changement de formule (prorata Stripe), portail client. L'état Stripe est recopié sur la
 * ligne locale (applyStripeState) ; les webhooks font de même ensuite (HandleSubscriptionWebhookUseCase).
 *
 * Taxes : fournisseur « stripe » → taxe automatique de Stripe Billing ; « table » → taux de taxe Stripe créés d'après la
 * table du site pour l'adresse de l'abonnement (sans adresse : pas de taxe).
 */
final class SubscriptionService
{
    /** Essais de paiement refusés d'une échéance avant résiliation (webhook invoice.payment_failed, 13/10/2026) */
    public const MAX_FAILED_PAYMENTS = 4;

    private const STRIPE_STATUSES = [
        'incomplete' => Subscription::STATUS_INCOMPLETE, 'incomplete_expired' => Subscription::STATUS_CANCELED, 'trialing' => Subscription::STATUS_TRIALING,
        'active' => Subscription::STATUS_ACTIVE, 'past_due' => Subscription::STATUS_PAST_DUE, 'unpaid' => Subscription::STATUS_PAST_DUE,
        'canceled' => Subscription::STATUS_CANCELED, 'paused' => Subscription::STATUS_PAUSED,
    ];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenantProvider,
        private readonly SubscriptionStripeGatewayInterface $stripe,
        private readonly BoutiqueSettingsService $settings,
        private readonly TaxEngine $taxes,
        private readonly ?\App\Services\TenantConnectionManager $tenants = null // site cible d'une formule branchée (lot 3 bis, 04/11/2026)
    ) {
    }

    /** @return list<SubscriptionPlanOutputDto> */
    public function planDtos(?int $productId, string $locale): array
    {
        return array_map(fn ($p) => SubscriptionPlanOutputDto::fromEntity($p, $locale), $this->plans()->findActive($productId));
    }

    /** @throws SubscriptionException 403 module désactivé, 404 formule, 422 produit non abonnable ou adresse d'un autre client */
    public function subscribe(User $user, int $planId, int $quantity, ?int $addressId, ?int $carrierId, ?string $fulfillmentTarget = null): array
    {
        if ($this->settings->commerceSetting('subscriptionsEnabled') === false) {
            throw new SubscriptionException(403, 'Les abonnements sont désactivés sur ce site.', [], [], 'SUBSCRIPTIONS_DISABLED');
        }
        $plan = $this->plans()->find($planId);
        if ($plan === null || !$plan->isActive()) {
            throw new SubscriptionException(404, 'Formule introuvable.', [], [], 'PLAN_NOT_FOUND');
        }
        if (!$plan->getProduct()?->isSubscriptionEnabled()) {
            throw new SubscriptionException(422, 'Ce produit ne se vend pas par abonnement.', [SubscriptionException::error('planId', 'PLAN_NOT_SUBSCRIBABLE', 'produit sans abonnement')], [], 'PLAN_NOT_SUBSCRIBABLE');
        }
        if ($quantity < 1 || $quantity > 100) {
            throw new SubscriptionException(422, 'quantity : entier de 1 à 100 attendu', [SubscriptionException::error('quantity', 'QUANTITY_INVALID', 'entier de 1 à 100 attendu', ['min' => 1, 'max' => 100])], [], 'QUANTITY_INVALID');
        }
        $em = $this->emProvider->getEntityManager();
        $address = null;
        if ($addressId !== null) {
            $address = $em->getRepository(Adress::class)->find($addressId);
            if ($address === null || $address->getUserAdress()?->getId() !== $user->getId()) {
                throw new SubscriptionException(422, 'addressId : adresse introuvable', [SubscriptionException::error('addressId', 'ADDRESS_NOT_FOUND', 'adresse introuvable pour ce client')], [], 'ADDRESS_NOT_FOUND');
            }
        }
        $carrier = $carrierId !== null ? $em->getRepository(Carrier::class)->find($carrierId) : null;
        if ($carrierId !== null && $carrier === null) {
            throw new SubscriptionException(422, 'carrierId : transporteur introuvable', [SubscriptionException::error('carrierId', 'CARRIER_NOT_FOUND', 'transporteur introuvable')], [], 'CARRIER_NOT_FOUND');
        }
        if ($carrier?->getCarrierAccountId()) {
            // Le tarif d'un transporteur EasyPost dépend des colis et de l'adresse : impossible à facturer d'avance à chaque échéance
            throw new SubscriptionException(422, 'carrierId : un abonnement exige un transporteur à prix fixe ou gratuit', [SubscriptionException::error('carrierId', 'CARRIER_RATES_NOT_ALLOWED', 'transporteur à tarif variable (EasyPost) : choisissez un transporteur à prix fixe ou gratuit')], [], 'CARRIER_RATES_NOT_ALLOWED');
        }
        $shippingAmount = $carrier !== null && !$carrier->isFree() ? (int) round((float) $carrier->getPrice()) : 0;

        // Deux souscriptions simultanées du même client au même produit (deux onglets, double clic) : la seconde attend la
        // fin de la première, puis la trouve (13/10/2026). Verrou de session PostgreSQL, libéré à la fin (ou à la
        // déconnexion si le processus s'arrête).
        $lock = 'subscription:' . $user->getId() . ':' . $plan->getProduct()->getId();
        $connection = $em->getConnection();
        $connection->executeQuery('SELECT pg_advisory_lock(hashtext(?))', [$lock]);
        try {
            return $this->subscribeLocked($user, $plan, $quantity, $address, $carrier, $shippingAmount, $this->fulfillmentTarget($plan, $fulfillmentTarget));
        } finally {
            $connection->executeQuery('SELECT pg_advisory_unlock(hashtext(?))', [$lock]);
        }
    }

    /**
     * Formule branchée sur une offre de la plateforme (lot 3 bis, 04/11/2026) : le site cible est obligatoire et doit exister ;
     * formule ordinaire : ignoré. 422 FULFILLMENT_TARGET_REQUIRED | FULFILLMENT_TARGET_UNKNOWN.
     */
    private function fulfillmentTarget(SubscriptionPlan $plan, ?string $target): ?string
    {
        if (!$plan->hasFulfillment()) {
            return null;
        }
        $target = trim((string) $target);
        if ($target === '') {
            throw new SubscriptionException(422, 'fulfillmentTarget : code du site à activer attendu', [SubscriptionException::error('fulfillmentTarget', 'FULFILLMENT_TARGET_REQUIRED', 'code du site à activer attendu')], [], 'FULFILLMENT_TARGET_REQUIRED');
        }
        if (!preg_match('/^[a-z0-9-]{2,50}$/', $target) || $this->tenants === null || !is_array($this->tenants->findTenantByCode($target))) {
            throw new SubscriptionException(422, 'fulfillmentTarget : site inconnu', [SubscriptionException::error('fulfillmentTarget', 'FULFILLMENT_TARGET_UNKNOWN', 'site inconnu')], [], 'FULFILLMENT_TARGET_UNKNOWN');
        }

        return $target;
    }

    /** @return array{subscription: Subscription, clientSecret: ?string, intentType: ?string, reused: bool} */
    private function subscribeLocked(User $user, SubscriptionPlan $plan, int $quantity, ?Adress $address, ?Carrier $carrier, int $shippingAmount, ?string $fulfillmentTarget = null): array
    {
        $em = $this->emProvider->getEntityManager();
        // Double souscription (double clic, nouvel essai après une erreur, 09/10/2026) : un essai sans carte (même formule et
        // quantité) est repris avec son SetupIntent (25/10/2026) ; un abonnement encore incomplet à
        // la même formule et quantité est repris (même paiement à confirmer) ; un incomplet d'une autre formule, ou
        // expiré chez Stripe (23 h sans paiement), est annulé ; un abonnement en cours (essai, actif, impayé, en pause,
        // résiliation programmée comprise) au même produit refuse la souscription (409 already_subscribed), quelle que
        // soit la formule : pour en changer, POST /api/subscriptions/{id}/change-plan.
        foreach ($this->subscriptions()->findOpenForProduct($user, $plan->getProduct()) as $open) {
            // Essai dont la carte n'a jamais été enregistrée (25/10/2026, demande du frontend) : même formule et quantité,
            // repris avec son SetupIntent au lieu d'un 409, sur n'importe quel appareil
            if ($open->getStatus() === Subscription::STATUS_TRIALING && $open->hasPaymentMethod() !== true && $open->getStripeSubscriptionId()
                && $open->getPlan()?->getId() === $plan->getId() && $open->getQuantity() === $quantity) {
                $state = $this->stripe->retrieve($open->getStripeSubscriptionId());
                $this->applyStripeState($open, $state);
                $em->flush();
                if ($state['status'] === 'trialing' && $state['hasPaymentMethod'] !== true && ($state['intentType'] ?? null) === 'setup' && $state['clientSecret']) {
                    return ['subscription' => $open, 'clientSecret' => $state['clientSecret'], 'intentType' => 'setup', 'reused' => true];
                }
            }
            if ($open->getStatus() !== Subscription::STATUS_INCOMPLETE) {
                throw new SubscriptionException(409, 'Vous êtes déjà abonné à ce produit : changez de formule ou résiliez depuis votre compte.',
                    [SubscriptionException::error('planId', 'ALREADY_SUBSCRIBED', sprintf('abonnement n° %d déjà en cours', $open->getId()), ['subscriptionId' => $open->getId(), 'status' => $open->getStatus()])],
                    ['reason' => 'already_subscribed', 'subscriptionId' => $open->getId(), 'status' => $open->getStatus()], 'ALREADY_SUBSCRIBED');
            }
            $state = $open->getStripeSubscriptionId() ? $this->stripe->retrieve($open->getStripeSubscriptionId()) : null;
            if ($state !== null && $state['status'] === 'incomplete' && $open->getPlan()?->getId() === $plan->getId() && $open->getQuantity() === $quantity && $state['clientSecret']) {
                $this->applyStripeState($open, $state);
                $em->flush();

                return ['subscription' => $open, 'clientSecret' => $state['clientSecret'], 'intentType' => $state['intentType'] ?? 'payment', 'reused' => true];
            }
            if ($state !== null && $state['status'] === 'incomplete') {
                $this->applyStripeState($open, $this->stripe->cancel($open->getStripeSubscriptionId(), false));
            }
            $open->setStatus(Subscription::STATUS_CANCELED)->setCanceledAt($open->getCanceledAt() ?? new \DateTimeImmutable());
            $em->flush();
        }

        $customerId = $this->stripe->ensureCustomer($user, $address);
        [, $priceId] = $this->stripe->ensurePrice($plan);
        $shippingPriceId = $shippingAmount > 0 ? $this->stripe->ensureShippingPrice($carrier, $shippingAmount, $plan->getCurrency(), $plan->getInterval(), $plan->getIntervalCount()) : null;
        $automaticTax = $this->taxes->provider() === TaxEngine::PROVIDER_STRIPE;
        $taxRateIds = [];
        if (!$automaticTax && $address !== null) {
            $normalized = TaxEngine::normalizeAddress(['country' => $address->getCountry(), 'province' => $address->getProvince(), 'city' => $address->getCity(), 'postalCode' => $address->getCodepostal()]);
            $taxRateIds = $normalized ? $this->stripe->taxRateIds(array_map(fn ($t) => ['label' => (string) $t->getName(), 'rate' => (float) $t->getRate()], $this->taxes->applicableTaxes($normalized))) : [];
        }

        $subscription = (new Subscription())->setUser($user)->setPlan($plan)->setQuantity($quantity)->setAddress($address)->setCarrier($carrier)->setShippingAmount($shippingAmount)->setStripeCustomerId($customerId)->setFulfillmentTarget($fulfillmentTarget);
        $em->persist($subscription);
        $em->flush();

        $state = $this->stripe->create($customerId, $priceId, $quantity, $plan->getTrialDays(), [
            'tenant_code' => (string) $this->tenantProvider->getTenantCode(), 'subscription_id' => (string) $subscription->getId(), 'user_id' => (string) $user->getId(),
        ], $taxRateIds, $automaticTax, $shippingPriceId);
        $subscription->setStripeSubscriptionId($state['id']);
        $this->applyStripeState($subscription, $state);
        $em->flush();

        return ['subscription' => $subscription, 'clientSecret' => $state['clientSecret'], 'intentType' => $state['intentType'] ?? null, 'reused' => false];
    }

    /** @throws SubscriptionException 404 */
    public function ownedBy(int $id, User $user): Subscription
    {
        $subscription = $this->subscriptions()->find($id);
        if ($subscription === null || $subscription->getUser()?->getId() !== $user->getId()) {
            throw new SubscriptionException(404, 'Abonnement introuvable.', [], [], 'SUBSCRIPTION_NOT_FOUND');
        }

        return $subscription;
    }

    public function cancel(Subscription $subscription, bool $atPeriodEnd): Subscription
    {
        $this->requireStripe($subscription);
        $this->applyStripeState($subscription, $this->stripe->cancel($subscription->getStripeSubscriptionId(), $atPeriodEnd));
        if (!$atPeriodEnd) {
            $subscription->setStatus(Subscription::STATUS_CANCELED)->setCanceledAt(new \DateTimeImmutable());
        }
        $this->emProvider->getEntityManager()->flush();

        return $subscription;
    }

    public function resume(Subscription $subscription): Subscription
    {
        $this->requireStripe($subscription);
        if ($subscription->getStatus() === Subscription::STATUS_CANCELED) {
            throw new SubscriptionException(409, 'Abonnement déjà résilié : souscrivez de nouveau.', [], [], 'SUBSCRIPTION_CANCELED');
        }
        $this->applyStripeState($subscription, $this->stripe->resume($subscription->getStripeSubscriptionId()));
        $subscription->setPausedAt(null);
        $this->emProvider->getEntityManager()->flush();

        return $subscription;
    }

    public function pause(Subscription $subscription): Subscription
    {
        $this->requireStripe($subscription);
        if (!in_array($subscription->getStatus(), [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING], true)) {
            throw new SubscriptionException(409, 'Seul un abonnement actif peut être mis en pause.', [], [], 'SUBSCRIPTION_NOT_ACTIVE');
        }
        $this->applyStripeState($subscription, $this->stripe->pause($subscription->getStripeSubscriptionId()));
        $subscription->setStatus(Subscription::STATUS_PAUSED)->setPausedAt(new \DateTimeImmutable());
        $this->emProvider->getEntityManager()->flush();

        return $subscription;
    }

    /** @throws SubscriptionException 404 formule, 409 abonnement résilié, 422 autre produit */
    public function changePlan(Subscription $subscription, int $planId): Subscription
    {
        $this->requireStripe($subscription);
        if ($subscription->getStatus() === Subscription::STATUS_CANCELED) {
            throw new SubscriptionException(409, 'Abonnement résilié : souscrivez de nouveau.', [], [], 'SUBSCRIPTION_CANCELED');
        }
        $plan = $this->plans()->find($planId);
        if ($plan === null || !$plan->isActive()) {
            throw new SubscriptionException(404, 'Formule introuvable.', [], [], 'PLAN_NOT_FOUND');
        }
        if ($plan->getProduct()?->getId() !== $subscription->getPlan()?->getProduct()?->getId()) {
            throw new SubscriptionException(422, 'planId : la nouvelle formule doit concerner le même produit', [SubscriptionException::error('planId', 'PLAN_OTHER_PRODUCT', 'formule d\'un autre produit')], [], 'PLAN_OTHER_PRODUCT');
        }
        [, $priceId] = $this->stripe->ensurePrice($plan);
        $state = $this->stripe->retrieve($subscription->getStripeSubscriptionId());
        $this->applyStripeState($subscription, $this->stripe->changePlan($subscription->getStripeSubscriptionId(), (string) $state['itemId'], $priceId, $subscription->getQuantity()));
        $subscription->setPlan($plan)->touch();
        $this->emProvider->getEntityManager()->flush();

        return $subscription;
    }

    /** État relu chez Stripe (administration : webhook manqué, action faite dans le tableau de bord Stripe) */
    public function sync(Subscription $subscription): Subscription
    {
        $this->requireStripe($subscription);
        $this->applyStripeState($subscription, $this->stripe->retrieve($subscription->getStripeSubscriptionId()));
        $this->emProvider->getEntityManager()->flush();

        return $subscription;
    }

    /**
     * Rembourse en entier le dernier paiement encaissé (administration). L'abonnement n'est pas modifié : le résilier
     * ou le mettre en pause à part si besoin.
     *
     * @return array{refundId: string, amount: int, currency: string}
     * @throws SubscriptionException 409 aucun paiement à rembourser, 502 Stripe (paiement déjà remboursé…)
     */
    public function refundLatest(Subscription $subscription): array
    {
        $this->requireStripe($subscription);

        return $this->stripe->refundLatestPayment($subscription->getStripeSubscriptionId())
            ?? throw new SubscriptionException(409, 'Aucun paiement encaissé à rembourser pour cet abonnement.', [], [], 'NOTHING_TO_REFUND');
    }

    public function portalUrl(User $user, string $returnUrl): string
    {
        $customerId = $user->getStripeCustomerId() ?? $this->stripe->ensureCustomer($user, $user->getPrimaryAddress());

        return $this->stripe->portalUrl($customerId, $returnUrl);
    }

    /** Recopie de l'état Stripe (création, réponse d'une opération, webhook) */
    /**
     * Facture payée : la carte qui l'a payée devient celle de l'abonnement s'il n'en a pas (voir l'interface).
     *
     * @throws SubscriptionException|\Throwable erreur Stripe
     */
    public function ensureDefaultPaymentMethod(Subscription $subscription, string $invoiceId): ?string
    {
        if ($subscription->getStripeSubscriptionId() === null) {
            return null;
        }
        $paymentMethod = $this->stripe->ensureDefaultPaymentMethod($subscription->getStripeSubscriptionId(), $invoiceId);
        if ($paymentMethod !== null) {
            $subscription->setHasPaymentMethod(true);
        }

        return $paymentMethod;
    }

    public function applyStripeState(Subscription $subscription, array $state): void
    {
        if (($state['status'] ?? '') !== '') {
            $status = self::STRIPE_STATUSES[$state['status']] ?? $subscription->getStatus();
            if ($state['paused'] ?? false) {
                $status = Subscription::STATUS_PAUSED;
            }
            $subscription->setStatus($status);
            if ($status === Subscription::STATUS_CANCELED && $subscription->getCanceledAt() === null) {
                $subscription->setCanceledAt(isset($state['canceledAt']) ? (new \DateTimeImmutable())->setTimestamp((int) $state['canceledAt']) : new \DateTimeImmutable());
            }
        }
        if (isset($state['currentPeriodEnd'])) {
            $subscription->setCurrentPeriodEnd((new \DateTimeImmutable())->setTimestamp((int) $state['currentPeriodEnd']));
        }
        if (array_key_exists('cancelAtPeriodEnd', $state)) {
            $subscription->setCancelAtPeriodEnd((bool) $state['cancelAtPeriodEnd']);
        }
        if (($state['hasPaymentMethod'] ?? null) !== null) {
            $subscription->setHasPaymentMethod((bool) $state['hasPaymentMethod']);
        }
    }

    public function toDto(Subscription $subscription, string $locale): SubscriptionOutputDto
    {
        $orders = $this->emProvider->getEntityManager()->getRepository(Order::class)->findBy(['subscription' => $subscription], ['id' => 'DESC']);

        return SubscriptionOutputDto::fromEntity($subscription, $orders, $locale);
    }

    /** @return list<SubscriptionOutputDto> */
    public function listFor(User $user, string $locale): array
    {
        return array_map(fn ($s) => $this->toDto($s, $locale), $this->subscriptions()->findByUser($user));
    }

    public function plans(): SubscriptionPlanRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(SubscriptionPlan::class);
    }

    public function subscriptions(): SubscriptionRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(Subscription::class);
    }

    private function requireStripe(Subscription $subscription): void
    {
        if (!$subscription->getStripeSubscriptionId()) {
            throw new SubscriptionException(409, 'Abonnement sans abonnement Stripe : souscription non terminée.', [], [], 'SUBSCRIPTION_NOT_STARTED');
        }
    }
}
