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
        private readonly ?\App\Abo\Services\AboRightsService $rights = null) // lot ABO-1 : solde, pause datée, engagement minimal
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
     * @param array<string, mixed> $body { planId, quantity?, addressId?, carrierId? }
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
        $result = $this->service->subscribe($user, (int) $planId, (int) $quantity, self::id($body['addressId'] ?? null), self::id($body['carrierId'] ?? null), is_string($target) ? trim($target) : null, is_string($link) ? trim($link) : null);

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
            $this->mailer->notify($subscription, 'cancel_at_term_end', $locale, $host, ['rows' => [($locale === 'en' ? 'Ends on' : 'Fin de l\'abonnement') => $this->rights->at($subscription->getCancelAt()) !== null
                ? \DateTimeImmutable::createFromFormat(\DATE_ATOM, (string) $this->rights->at($subscription->getCancelAt()))->format('d/m/Y') : '']]);
        } else {
            $this->mailer->notify($subscription, $engaged || $atPeriodEnd ? 'cancel_scheduled' : 'canceled', $locale, $host);
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
        $this->mailer->notify($subscription, 'paused', $locale, $host);

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
