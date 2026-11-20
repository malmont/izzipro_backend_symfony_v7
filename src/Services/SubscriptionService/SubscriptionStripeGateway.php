<?php

namespace App\Services\SubscriptionService;

use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\StripeConfig;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Services\TenantConnectionProvider;
use App\Services\StripeService\StripeConnectSetupService;
use App\Services\TenantEntityManagerProvider;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stripe Billing sur le compte connecté du site (StripeConfig actif ; sinon le compte de la plateforme). Les
 * identifiants Stripe créés (client, produit, prix, taux de taxe) sont gardés en base pour ne pas être recréés : client et
 * prix de la formule sur leur fiche, taux de taxe et prix de livraison dans `stripe_object_ref` de la base maître (lot
 * CACHE-ETAT, StripeObjectRefStore : clé de tous les paramètres, mode, verrou, référence périmée recréée). Toute erreur de
 * l'API remonte en SubscriptionException 502.
 */
final class SubscriptionStripeGateway implements SubscriptionStripeGatewayInterface
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenantProvider,
        private readonly StripeObjectRefStore $refs, // lot CACHE-ETAT : plus de cache pour les identifiants Stripe
        private readonly StripeConnectSetupService $setup,
        #[Autowire('%env(default::STRIPE_SECRET_KEY)%')]
        private readonly ?string $stripeSecretKey,
        private readonly ?\App\Services\Http\ClientSafeError $safeError = null, // lot ERREURS-1 (12/11/2026)
    ) {
    }

    public function ensureCustomer(User $user, ?Adress $address): string
    {
        if ($user->getStripeCustomerId()) {
            return $user->getStripeCustomerId();
        }
        $customer = $this->call(fn (StripeClient $c, array $o) => $c->customers->create(array_filter([
            'email' => $user->getEmail(),
            'name' => trim($user->getFirstname() . ' ' . $user->getLastname()) ?: null,
            'address' => $address ? array_filter(['line1' => $address->getAddress(), 'city' => $address->getCity(), 'state' => $address->getProvince(), 'postal_code' => $address->getCodepostal(), 'country' => $address->getCountry()]) : null,
            'metadata' => ['tenant_code' => (string) $this->tenantProvider->getTenantCode(), 'user_id' => (string) $user->getId()],
        ]), $o));
        $user->setStripeCustomerId($customer->id);
        $this->emProvider->getEntityManager()->flush();

        return $customer->id;
    }

    public function deleteCustomer(string $customerId): void
    {
        $this->call(fn (StripeClient $c, array $o) => $c->customers->delete($customerId, [], $o));
    }

    public function ensurePrice(SubscriptionPlan $plan): array
    {
        if ($plan->getStripePriceId()) {
            return [$plan->getStripeProductId(), $plan->getStripePriceId()];
        }
        $productId = $plan->getStripeProductId() ?: $this->call(fn (StripeClient $c, array $o) => $c->products->create([
            'name' => sprintf('%s — %s', $plan->getProduct()?->getName() ?? 'Abonnement', $plan->getName()),
            'metadata' => ['tenant_code' => (string) $this->tenantProvider->getTenantCode(), 'plan_id' => (string) $plan->getId()],
        ], $o))->id;
        $price = $this->call(fn (StripeClient $c, array $o) => $c->prices->create([
            'product' => $productId, 'unit_amount' => $plan->getPrice(), 'currency' => strtolower($plan->getCurrency()),
            'recurring' => ['interval' => $plan->getInterval(), 'interval_count' => $plan->getIntervalCount()],
            'metadata' => ['plan_id' => (string) $plan->getId()],
        ], $o));
        $plan->setStripeProductId($productId)->setStripePriceId($price->id);
        $this->emProvider->getEntityManager()->flush();

        return [$productId, $price->id];
    }

    public function taxRateIds(array $taxes): array
    {
        $ids = [];
        foreach ($taxes as $tax) {
            $params = self::taxRateParams($tax);
            $ref = StripeObjectRefStore::refKey($params);
            [$account, $mode] = [$this->account() ?? 'platform', $this->mode()];
            $ids[] = $this->refs->ensure($account, $mode, 'tax_rate', $ref,
                fn (string $id): bool => $this->retrieveAlive(fn (StripeClient $c, array $o) => (bool) $c->taxRates->retrieve($id, [], $o)->active),
                fn (int $generation): string => $this->call(fn (StripeClient $c, array $o) => $c->taxRates->create($params, $o + ['idempotency_key' => StripeObjectRefStore::idempotencyKey($generation, 'tax', $account, $mode, $ref)]))->id);
        }

        return $ids;
    }

    /**
     * Paramètres de création d'un taux de taxe : TOUS entrent dans la clé de l'objet (lot CACHE-ETAT).
     *
     * @param array{label: string, rate: float|int|string} $tax
     */
    public static function taxRateParams(array $tax): array
    {
        return [
            'display_name' => (string) $tax['label'], 'percentage' => round((float) $tax['rate'] * 100, 4), 'inclusive' => false,
            'country' => 'CA', 'description' => 'Taxe du site (table)',
        ];
    }

    public function ensureShippingPrice(Carrier $carrier, int $amount, string $currency, string $interval, int $intervalCount): string
    {
        [$productParams, $priceParams] = self::shippingParams((int) $carrier->getId(), (string) $carrier->getName(), (string) $this->tenantProvider->getTenantCode(), $amount, $currency, $interval, $intervalCount);
        $ref = StripeObjectRefStore::refKey(['product' => $productParams, 'price' => $priceParams]);

        [$account, $mode] = [$this->account() ?? 'platform', $this->mode()];

        return $this->refs->ensure($account, $mode, 'shipping_price', $ref,
            // le prix ET son produit doivent être actifs (un produit archivé chez Stripe refuse les nouveaux abonnements)
            fn (string $id): bool => $this->retrieveAlive(function (StripeClient $c, array $o) use ($id): bool {
                $price = $c->prices->retrieve($id, ['expand' => ['product']], $o);

                return (bool) $price->active && is_object($price->product) && (bool) ($price->product->active ?? false);
            }),
            function (int $generation) use ($productParams, $priceParams, $ref, $account, $mode): string {
                $product = $this->call(fn (StripeClient $c, array $o) => $c->products->create($productParams, $o + ['idempotency_key' => StripeObjectRefStore::idempotencyKey($generation, 'prod', $account, $mode, $ref)]));

                return $this->call(fn (StripeClient $c, array $o) => $c->prices->create(['product' => $product->id] + $priceParams, $o + ['idempotency_key' => StripeObjectRefStore::idempotencyKey($generation, 'price', $account, $mode, $ref)]))->id;
            });
    }

    /**
     * Paramètres de création du produit et du prix de livraison : TOUS entrent dans la clé de l'objet (lot CACHE-ETAT).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function shippingParams(int $carrierId, string $carrierName, string $tenantCode, int $amount, string $currency, string $interval, int $intervalCount): array
    {
        return [
            [
                'name' => sprintf('Livraison — %s', $carrierName), 'tax_code' => 'txcd_92010001', // code fiscal « livraison » de Stripe Tax
                'metadata' => ['tenant_code' => $tenantCode, 'carrier_id' => (string) $carrierId],
            ],
            ['unit_amount' => $amount, 'currency' => strtolower($currency), 'recurring' => ['interval' => $interval, 'interval_count' => $intervalCount]],
        ];
    }

    public function create(string $customerId, string $priceId, int $quantity, int $trialDays, array $metadata, array $taxRateIds, bool $automaticTax, ?string $shippingPriceId = null, bool $cancelAtPeriodEnd = false, ?float $applicationFeePercent = null): array
    {
        $items = [['price' => $priceId, 'quantity' => $quantity, 'metadata' => ['role' => 'plan']]];
        if ($shippingPriceId !== null) {
            $items[] = ['price' => $shippingPriceId, 'quantity' => 1, 'metadata' => ['role' => 'shipping']];
        }
        $params = [
            'customer' => $customerId,
            'items' => $items,
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
            'metadata' => $metadata,
            // API 2025-03+ : le secret de la première facture est dans latest_invoice.confirmation_secret (payment_intent n'est plus exposé)
            'expand' => ['latest_invoice.confirmation_secret', 'latest_invoice.payment_intent', 'pending_setup_intent'],
        ];
        if ($trialDays > 0) {
            $params['trial_period_days'] = $trialDays;
        }
        if ($cancelAtPeriodEnd) {
            $params['cancel_at_period_end'] = true; // lot ABO-1 : carte de séances, payée une fois, fin posée dès la création
        }
        if ($applicationFeePercent !== null) {
            $params['application_fee_percent'] = $applicationFeePercent; // lot ABO-CONF C : commission de la plateforme (compte connecté seulement)
        }
        if ($automaticTax) {
            $params['automatic_tax'] = ['enabled' => true];
        } elseif ($taxRateIds) {
            $params['default_tax_rates'] = $taxRateIds;
        }

        return self::normalize($this->call(fn (StripeClient $c, array $o) => $c->subscriptions->create($params, $o)));
    }

    public function retrieve(string $subscriptionId): array
    {
        return self::normalize($this->call(fn (StripeClient $c, array $o) => $c->subscriptions->retrieve($subscriptionId, ['expand' => ['latest_invoice.confirmation_secret', 'latest_invoice.payment_intent', 'pending_setup_intent']], $o)));
    }

    public function cancel(string $subscriptionId, bool $atPeriodEnd): array
    {
        if (!$atPeriodEnd) {
            return self::normalize($this->call(fn (StripeClient $c, array $o) => $c->subscriptions->cancel($subscriptionId, [], $o)));
        }

        return self::normalize($this->call(fn (StripeClient $c, array $o) => $c->subscriptions->update($subscriptionId, ['cancel_at_period_end' => true], $o)));
    }

    public function updateFee(string $subscriptionId, float $percent, string $idempotencyKey): array
    {
        return self::normalize($this->call(fn (StripeClient $c, array $o) => $c->subscriptions->update($subscriptionId, ['application_fee_percent' => $percent], $o + ['idempotency_key' => $idempotencyKey])));
    }

    public function cancelAt(string $subscriptionId, int $timestamp): array
    {
        return self::normalize($this->call(fn (StripeClient $c, array $o) => $c->subscriptions->update($subscriptionId, ['cancel_at' => $timestamp, 'proration_behavior' => 'none'], $o)));
    }

    public function resume(string $subscriptionId, bool $liftCancellation = true, bool $scheduledAt = false): array
    {
        // Stripe refuse `cancel_at` et `cancel_at_period_end` dans la même requête : on lève l'une ou l'autre, selon la fin programmée
        $params = ['pause_collection' => ''] + ($liftCancellation ? ($scheduledAt ? ['cancel_at' => ''] : ['cancel_at_period_end' => false]) : []);

        return self::normalize($this->call(fn (StripeClient $c, array $o) => $c->subscriptions->update($subscriptionId, $params, $o)));
    }

    public function pause(string $subscriptionId, ?int $resumesAt = null): array
    {
        $pause = ['behavior' => 'void'] + ($resumesAt !== null ? ['resumes_at' => $resumesAt] : []);

        return self::normalize($this->call(fn (StripeClient $c, array $o) => $c->subscriptions->update($subscriptionId, ['pause_collection' => $pause], $o)));
    }

    public function changePlan(string $subscriptionId, string $itemId, string $priceId, int $quantity): array
    {
        return self::normalize($this->call(fn (StripeClient $c, array $o) => $c->subscriptions->update($subscriptionId, [
            'items' => [['id' => $itemId, 'price' => $priceId, 'quantity' => $quantity]], 'proration_behavior' => 'create_prorations',
        ], $o)));
    }

    public function refundLatestPayment(string $subscriptionId): ?array
    {
        return $this->call(function (StripeClient $c, array $o) use ($subscriptionId): ?array {
            // API 2025-03+ : le paiement d'une facture est dans invoice.payments (à demander), plus dans invoice.payment_intent
            $invoice = $c->invoices->all(['subscription' => $subscriptionId, 'status' => 'paid', 'limit' => 1, 'expand' => ['data.payments']], $o)->data[0] ?? null;
            if ($invoice === null || (int) $invoice->amount_paid === 0) {
                return null;
            }
            $paymentIntent = null;
            foreach ($invoice->payments->data ?? [] as $payment) {
                if (($payment->status ?? null) === 'paid' && !empty($payment->payment->payment_intent)) {
                    $paymentIntent = is_string($payment->payment->payment_intent) ? $payment->payment->payment_intent : $payment->payment->payment_intent->id;
                    break;
                }
            }
            if ($paymentIntent === null) {
                return null;
            }
            try {
                $refund = $c->refunds->create(['payment_intent' => $paymentIntent, 'metadata' => ['subscription' => $subscriptionId, 'invoice' => (string) $invoice->id]], $o);
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                if ($e->getStripeCode() === 'charge_already_refunded') {
                    throw new SubscriptionException(409, 'Le dernier paiement de cet abonnement est déjà remboursé.', [], [], 'ALREADY_REFUNDED');
                }
                throw $e;
            }

            return ['refundId' => (string) $refund->id, 'amount' => (int) $refund->amount, 'currency' => (string) $refund->currency];
        });
    }

    public function ensureDefaultPaymentMethod(string $subscriptionId, string $invoiceId): ?string
    {
        return $this->call(function (StripeClient $c, array $o) use ($subscriptionId, $invoiceId): ?string {
            $subscription = $c->subscriptions->retrieve($subscriptionId, [], $o);
            if (!empty($subscription->default_payment_method)) {
                return null;
            }
            // API 2025-03+ : le paiement d'une facture est dans invoice.payments (à demander), plus dans invoice.payment_intent
            $invoice = $c->invoices->retrieve($invoiceId, ['expand' => ['payments.data.payment.payment_intent']], $o);
            $paymentMethod = null;
            foreach ($invoice->payments->data ?? [] as $payment) {
                $intent = $payment->payment->payment_intent ?? null;
                if (($payment->status ?? null) === 'paid' && is_object($intent) && !empty($intent->payment_method)) {
                    $paymentMethod = is_string($intent->payment_method) ? $intent->payment_method : $intent->payment_method->id;
                    break;
                }
            }
            if ($paymentMethod === null) {
                return null;
            }
            $c->subscriptions->update($subscriptionId, ['default_payment_method' => $paymentMethod], $o);

            return $paymentMethod;
        });
    }

    public function portalUrl(string $customerId, string $returnUrl): string
    {
        // Compte Express : aucun portail par défaut, la plateforme crée sa configuration (StripeConnectSetupService)
        $siteName = $this->emProvider->getEntityManager()->getRepository(\App\Entity\Entreprise::class)->findOneBy([])?->getName() ?? (string) $this->tenantProvider->getTenantCode();
        $configuration = $this->setup->ensurePortalConfiguration($siteName);

        return (string) $this->call(fn (StripeClient $c, array $o) => $c->billingPortal->sessions->create(array_filter(['customer' => $customerId, 'return_url' => $returnUrl, 'configuration' => $configuration]), $o))->url;
    }

    /** Forme commune d'un abonnement Stripe (objet du SDK ou tableau d'un webhook) */
    public static function normalize(StripeSubscription|array $s): array
    {
        $a = $s instanceof StripeSubscription ? $s->toArray() : $s;
        // Ligne de la formule : celle marquée role=plan (la livraison est une seconde ligne), sinon la première
        $items = $a['items']['data'] ?? [];
        $item = null;
        foreach ($items as $candidate) {
            if (($candidate['metadata']['role'] ?? 'plan') === 'plan') {
                $item = $candidate;
                break;
            }
        }
        $item ??= $items[0] ?? null;
        $paymentSecret = $a['latest_invoice']['confirmation_secret']['client_secret'] ?? $a['latest_invoice']['payment_intent']['client_secret'] ?? null;
        $setupSecret = $a['pending_setup_intent']['client_secret'] ?? null;

        return [
            'id' => (string) ($a['id'] ?? ''),
            'status' => (string) ($a['status'] ?? ''),
            'currentPeriodEnd' => isset($a['current_period_end']) ? (int) $a['current_period_end'] : (isset($item['current_period_end']) ? (int) $item['current_period_end'] : null),
            'cancelAtPeriodEnd' => (bool) ($a['cancel_at_period_end'] ?? false),
            'cancelAt' => isset($a['cancel_at']) ? (int) $a['cancel_at'] : null, // lot ABO-1 : résiliation programmée à une date
            'paused' => !empty($a['pause_collection']),
            // carte par défaut de l'abonnement (champ absent de l'objet : inconnu)
            'hasPaymentMethod' => array_key_exists('default_payment_method', $a) ? !empty($a['default_payment_method']) : null,
            'clientSecret' => $paymentSecret ?? $setupSecret,
            // nature du clientSecret : le frontend confirme un paiement (confirmPayment) ou un enregistrement de carte (confirmSetup)
            'intentType' => $paymentSecret !== null ? 'payment' : ($setupSecret !== null ? 'setup' : null),
            'itemId' => $item['id'] ?? null,
            'canceledAt' => isset($a['canceled_at']) ? (int) $a['canceled_at'] : null,
        ];
    }

    private function account(): ?string
    {
        return $this->emProvider->getEntityManager()->getRepository(StripeConfig::class)->findOneBy(['isActive' => true])?->getAccountId();
    }

    /** Mode de la clé du serveur (test / live) : il entre dans la clé des objets gardés (lot CACHE-ETAT) */
    private function mode(): string
    {
        return StripeObjectRefStore::modeOf($this->stripeSecretKey);
    }

    /**
     * L'objet gardé existe-t-il encore, actif, chez Stripe ? Disparu (resource_missing) = non ; toute autre erreur remonte (502).
     */
    private function retrieveAlive(callable $retrieve): bool
    {
        return (bool) $this->call(function (StripeClient $c, array $o) use ($retrieve): bool {
            try {
                return (bool) $retrieve($c, $o);
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                if ($e->getStripeCode() === 'resource_missing') {
                    return false;
                }
                throw $e;
            }
        });
    }

    /** @throws SubscriptionException 502 */
    private function call(callable $action): mixed
    {
        try {
            $account = $this->account();

            return $action(new StripeClient((string) $this->stripeSecretKey), $account ? ['stripe_account' => $account] : []);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // lot ERREURS-1 : carte refusée = texte de Stripe et decline_code ; toute autre erreur Stripe = texte neutre, détail au journal
            throw new SubscriptionException(502, 'Stripe : ' . $this->safeError->stripe($e, 'abonnement'), [], array_filter(['declineCode' => \App\Services\Http\ClientSafeError::declineCode($e)]), 'STRIPE_ERROR');
        }
    }
}
