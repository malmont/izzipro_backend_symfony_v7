<?php

namespace App\Abo\UseCase;

use App\Abo\Services\AboException;
use App\Abo\Services\AboFollowUpService;
use App\Abo\Services\AboRightsService;
use App\Entity\Subscription;
use App\Services\SubscriptionService\SubscriptionException;
use App\Services\SubscriptionService\SubscriptionLockTimeout;
use App\Services\TenantEntityManagerProvider;

/**
 * Gestes du quotidien de l'équipe sur un abonnement (lot ABO-1, 19/11/2026), gestion web et application de gestion mobile : utiliser un
 * droit (annulable 24 h), offrir des droits, pause datée, reprise, résiliation en fin de période. Chaque geste rend la fiche à jour.
 */
class AboDailyUseCase
{
    public function __construct(private readonly AboRightsService $rights, private readonly AboFollowUpService $followUp, private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return array<string, mixed> */
    public function use(int $id, array $body, ?string $by, string $locale): array
    {
        $result = $this->stripeRefusal(fn () => $this->rights->use($this->subscription($id), (int) ($body['benefitId'] ?? 0), isset($body['note']) ? (string) $body['note'] : null, $by));

        return ['use' => $result, 'subscription' => $this->followUp->subscription($id, $locale)];
    }

    /** @return array<string, mixed> */
    public function undoUse(int $id, int $occurrenceId, ?string $by, string $locale): array
    {
        $this->stripeRefusal(fn () => $this->rights->undoUse($this->subscription($id), $occurrenceId, $by));

        return $this->followUp->subscription($id, $locale);
    }

    /** @return array<string, mixed> */
    public function gift(int $id, array $body, ?string $by, string $locale): array
    {
        $this->stripeRefusal(fn () => $this->rights->gift($this->subscription($id), (int) ($body['benefitId'] ?? 0), (int) ($body['quantity'] ?? 0), isset($body['reason']) ? (string) $body['reason'] : null, $by));

        return $this->followUp->subscription($id, $locale);
    }

    /** @return array<string, mixed> */
    public function pause(int $id, array $body, ?string $by, string $locale): array
    {
        $this->stripeRefusal(fn () => $this->rights->pause($this->subscription($id), isset($body['until']) ? (string) $body['until'] : null, $by));

        return $this->followUp->subscription($id, $locale);
    }

    /** @return array<string, mixed> */
    public function resume(int $id, ?string $by, string $locale): array
    {
        $this->stripeRefusal(fn () => $this->rights->resume($this->subscription($id), $by));

        return $this->followUp->subscription($id, $locale);
    }

    /** @return array<string, mixed> */
    public function cancel(int $id, array $body, ?string $by, string $locale): array
    {
        if (($body['atPeriodEnd'] ?? true) !== true) {
            throw new AboException(422, 'CANCEL_AT_PERIOD_END_ONLY', 'Ici, la résiliation se fait en fin de période ; une résiliation immédiate se fait depuis l\'écran Abonnements d\'EasyAdmin.', 'atPeriodEnd');
        }
        $this->stripeRefusal(fn () => $this->rights->cancelAtPeriodEnd($this->subscription($id), true, isset($body['reason']) ? (string) $body['reason'] : null, $by));

        return $this->followUp->subscription($id, $locale);
    }

    private function subscription(int $id): Subscription
    {
        return $this->emProvider->getEntityManager()->getRepository(Subscription::class)->find($id) ?? throw new AboException(404, 'SUBSCRIPTION_NOT_FOUND', 'Abonnement introuvable.');
    }

    /**
     * Un refus de l'abonnement (statut, Stripe) garde son code ; un abonnement tenu trop longtemps par un autre traitement (webhook, autre
     * geste) rend 409 SUBSCRIPTION_BUSY, à refaire dans un instant, jamais une erreur 500.
     */
    private function stripeRefusal(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (SubscriptionException $e) {
            throw new AboException($e->getStatusCode(), $e->code(), $e->getMessage(), previous: $e);
        } catch (SubscriptionLockTimeout) {
            throw new AboException(409, 'SUBSCRIPTION_BUSY', 'Cet abonnement est en cours de traitement : réessayez dans un instant.');
        }
    }
}
