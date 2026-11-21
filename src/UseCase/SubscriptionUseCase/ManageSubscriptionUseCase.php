<?php

namespace App\UseCase\SubscriptionUseCase;

use App\Dto\SubscriptionOutputDto;
use App\Entity\User;
use App\Services\SubscriptionService\SubscriptionException;
use App\Services\SubscriptionService\SubscriptionMailer;
use App\Services\SubscriptionService\SubscriptionService;

/**
 * Routes du client connecté : POST /api/subscriptions (souscription), GET (liste, détail), POST …/{id}/cancel, resume,
 * pause, change-plan, POST /api/subscriptions/portal-session. Un abonnement d'un autre client : 404.
 */
class ManageSubscriptionUseCase
{
    public function __construct(private readonly SubscriptionService $service, private readonly SubscriptionMailer $mailer,
        private readonly ?\App\Abo\Services\AboRightsService $rights = null,
        private readonly ?\App\Abo\Services\AboCatalogService $catalog = null, // lot ABO-CONF : le prix que paie l'abonné // lot ABO-1 : solde, pause datée, engagement minimal
        private readonly ?\App\Abo\Services\AboAgendaService $agenda = null, // lot ABO-2 : prochaines dates, places retenues à la souscription
        private readonly ?\App\Abo\Services\AboDeliveryService $delivery = null, // lot ABO-3a : prestations faites, avis de l'abonné
        private readonly ?\App\Abo\Services\AboThreadService $thread = null) // lot ABO-3b : messages de la boutique pas encore lus
    {
    }

    /** L'abonnement du client, avec son solde par prestation (lot ABO-1 : nombres et échéances seulement) */
    private function dto(\App\Entity\Subscription $subscription, string $locale): SubscriptionOutputDto
    {
        $dto = $this->service->toDto($subscription, $locale);
        if ($this->rights === null) {
            return $dto;
        }
        // résiliation simple (décision du chef du 19/11/2026) : « Résilier » toujours possible tant que l'abonnement vit ; pendant un
        // engagement, la date à partir de laquelle il prend fin ; la fin déjà programmée, s'il y en a une
        [$engaged, $termEnd] = $this->rights->termEnd($subscription);
        $scheduled = $subscription->getCancelAt() ?? ($subscription->isCancelAtPeriodEnd() ? $subscription->getCurrentPeriodEnd() : null);

        if ($this->catalog !== null) {
            $dto = $dto->withBilledPrice($this->catalog->billedPrice($subscription)); // l'abonné en cours garde le prix de son époque
        }

        if ($this->agenda !== null) {
            $dto = $dto->withUpcoming($this->agenda->upcoming($subscription, false, $locale));
        }
        if ($this->delivery !== null) {
            $dto = $dto->withDelivered($this->delivery->delivered($subscription, false, $locale), $subscription->wantsDoneEmails());
        }
        if ($this->thread !== null) {
            $dto = $dto->withUnreadMessages($this->thread->unreadForCustomer($subscription));
        }

        return $dto->withBalance($this->rights->balance($subscription, $locale))->withCancellation([
            'allowed' => $subscription->getStatus() !== \App\Entity\Subscription::STATUS_CANCELED && $subscription->getStripeSubscriptionId() !== null,
            'cancellableFrom' => $engaged ? $this->rights->at($termEnd) : null,
            'scheduledAt' => $this->rights->at($scheduled),
        ]);
    }

    /** Un refus des droits devient un refus d'abonnement, avec son code et ses détails */
    private function aboRefusal(\App\Abo\Services\AboException $e): SubscriptionException
    {
        return new SubscriptionException($e->status, $e->getMessage(), $e->field !== null ? [SubscriptionException::error($e->field, $e->errorCode, $e->getMessage())] : [], $e->details, $e->errorCode, $e);
    }

    /**
     * @param array<string, mixed> $body { planId, quantity?, addressId?, carrierId?, slots? } ; `slots` (lot ABO-2, Q1) : [{ benefitId, slotId }],
     *        places retenues 30 minutes pendant le paiement, puis rendez-vous à la naissance des droits ; libérées si la souscription échoue
     * @return array{subscriptionId: int, clientSecret: ?string, intentType: ?string, status: string, reused: bool, subscription: SubscriptionOutputDto}
     * @throws SubscriptionException
     */
    public function subscribe(User $user, array $body, string $locale): array
    {
        $planId = $body['planId'] ?? null;
        if (!is_numeric($planId)) {
            throw new SubscriptionException(422, 'planId : identifiant de formule attendu', [SubscriptionException::error('planId', 'PLAN_ID_REQUIRED', 'identifiant attendu')], [], 'PLAN_ID_REQUIRED');
        }
        $quantity = $body['quantity'] ?? 1;
        if (!is_numeric($quantity) || (int) $quantity != $quantity) {
            throw new SubscriptionException(422, 'quantity : entier attendu', [SubscriptionException::error('quantity', 'QUANTITY_INVALID', 'entier attendu', ['min' => 1, 'max' => 100])], [], 'QUANTITY_INVALID');
        }
        $target = $body['fulfillmentTarget'] ?? null; // site cible d'une formule branchée sur une offre de la plateforme (lot 3 bis, 04/11/2026)
        $link = $body['fulfillmentLink'] ?? null; // lot PROVISION-1 : le lien signé de « Mettre à jour mon forfait »
        $holds = [];
        if ($this->agenda !== null && array_key_exists('slots', $body) && $body['slots'] !== null && $body['slots'] !== []) {
            $plan = $this->service->plans()->find((int) $planId);
            if ($plan === null || !$plan->isActive()) {
                throw new SubscriptionException(404, 'Formule introuvable.', [], [], 'PLAN_NOT_FOUND');
            }
            try {
                $holds = $this->agenda->hold($user, $plan, (int) $quantity, $body['slots']); // avant tout appel à Stripe : une place complète refuse tôt
            } catch (\App\Abo\Services\AboException $e) {
                throw $this->aboRefusal($e);
            }
        }
        try {
            $result = $this->service->subscribe($user, (int) $planId, (int) $quantity, self::id($body['addressId'] ?? null), self::id($body['carrierId'] ?? null), is_string($target) ? trim($target) : null, is_string($link) ? trim($link) : null);
        } catch (\Throwable $e) {
            $this->agenda?->releaseHolds($holds); // souscription refusée : les places sont rendues tout de suite
            throw $e;
        }
        $this->agenda?->attachHolds($holds, $result['subscription']);
        if ($holds !== [] && ($result['reused'] ?? false)) {
            $this->agenda?->convertNow($result['subscription']); // souscription reprise : ses droits sont peut-être déjà nés (essai)
        }

        return [
            'subscriptionId' => (int) $result['subscription']->getId(), 'clientSecret' => $result['clientSecret'],
            // payment : confirmer le paiement de la première facture ; setup : enregistrer la carte (essai gratuit, rien à payer)
            'intentType' => $result['intentType'] ?? null, 'status' => $result['subscription']->getStatus(),
            'reused' => $result['reused'] ?? false,
            'subscription' => $this->dto($result['subscription'], $locale),
        ];
    }

    /** @return list<SubscriptionOutputDto> */
    public function list(User $user, string $locale): array
    {
        return array_map(fn ($s) => $this->dto($s, $locale), $this->service->subscriptions()->findByUser($user));
    }

    public function get(int $id, User $user, string $locale): SubscriptionOutputDto
    {
        return $this->dto($this->service->ownedBy($id, $user), $locale);
    }

    public function cancel(int $id, User $user, bool $atPeriodEnd, string $locale, string $host): SubscriptionOutputDto
    {
        $subscription = $this->service->ownedBy($id, $user);
        // lot ABO-1 (décision du chef du 19/11/2026, résiliation simple) : pendant l'engagement minimal, la résiliation du client est
        // PROGRAMMÉE à la fin de l'engagement, en fin de période comme en résiliation immédiate demandée ; jamais refusée
        $engaged = $this->rights !== null && $this->rights->termEnd($subscription)[0];
        try {
            $subscription = match (true) {
                $engaged => $this->rights->cancelAtTermEnd($subscription),
                $atPeriodEnd && $this->rights !== null => $this->rights->cancelAtPeriodEnd($subscription, false, null, null),
                default => $this->service->cancel($subscription, $atPeriodEnd),
            };
        } catch (\App\Abo\Services\AboException $e) {
            throw $this->aboRefusal($e);
        }
        if ($engaged && $subscription->getCancelAt() !== null) {
            $this->mailer->notify($subscription, 'cancel_at_term_end', $host, ['rows' => ['subscription_notice.row.ends_on' => $this->rights->at($subscription->getCancelAt()) !== null
                ? \DateTimeImmutable::createFromFormat(\DATE_ATOM, (string) $this->rights->at($subscription->getCancelAt()))->format('d/m/Y') : '']]);
        } else {
            $this->mailer->notify($subscription, $engaged || $atPeriodEnd ? 'cancel_scheduled' : 'canceled', $host);
        }

        return $this->dto($subscription, $locale);
    }

    public function resume(int $id, User $user, string $locale): SubscriptionOutputDto
    {
        $subscription = $this->service->ownedBy($id, $user);

        return $this->dto($this->rights !== null ? $this->rights->resume($subscription, null, false) : $this->service->resume($subscription), $locale);
    }

    /** Lot ABO-1 : `until` (AAAA-MM-JJ, heure du magasin) facultatif ; sans date, la durée maximale de la formule ; limites de la formule */
    public function pause(int $id, User $user, string $locale, string $host, ?string $until = null): SubscriptionOutputDto
    {
        $subscription = $this->service->ownedBy($id, $user);
        try {
            $subscription = $this->rights !== null ? $this->rights->pause($subscription, $until, null) : $this->service->pause($subscription);
        } catch (\App\Abo\Services\AboException $e) {
            throw $this->aboRefusal($e);
        }
        $this->mailer->notify($subscription, 'paused', $host);

        return $this->dto($subscription, $locale);
    }

    public function changePlan(int $id, User $user, mixed $planId, string $locale): SubscriptionOutputDto
    {
        if (!is_numeric($planId)) {
            throw new SubscriptionException(422, 'planId : identifiant de formule attendu', [SubscriptionException::error('planId', 'PLAN_ID_REQUIRED', 'identifiant attendu')], [], 'PLAN_ID_REQUIRED');
        }

        return $this->dto($this->service->changePlan($this->service->ownedBy($id, $user), (int) $planId), $locale);
    }

    public function portalUrl(User $user, ?string $returnUrl, string $fallbackUrl): string
    {
        $url = is_string($returnUrl) && preg_match('#^https://[^\s<>"\']+$#', $returnUrl) ? $returnUrl : $fallbackUrl;

        return $this->service->portalUrl($user, $url);
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
