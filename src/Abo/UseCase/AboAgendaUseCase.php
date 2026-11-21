<?php

namespace App\Abo\UseCase;

use App\Abo\Entity\BenefitOccurrence;
use App\Abo\Services\AboAgendaService;
use App\Abo\Services\AboException;
use App\Abo\Services\AboFollowUpService;
use App\Entity\Subscription;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Services\SubscriptionService\SubscriptionException;
use App\Services\SubscriptionService\SubscriptionLockTimeout;
use App\Services\TenantEntityManagerProvider;

/**
 * Agenda des prestations (lot ABO-2, 20/11/2026) :
 * - l'ÉQUIPE (gestion web et application de gestion mobile, `ROLE_ADMIN`, module Boutique) : créneaux et places restantes, droits à
 *   planifier, donner / déplacer / annuler une date (sans perte, au journal), annuler un créneau (courriel aux abonnés) ;
 * - l'ABONNÉ (boutique web et commerce mobile) : créneaux ouverts à son choix, réserver, déplacer et annuler avant le délai.
 * Chaque geste sur un abonnement rend la fiche à jour.
 */
class AboAgendaUseCase
{
    public function __construct(private readonly AboAgendaService $agenda, private readonly AboFollowUpService $followUp, private readonly TenantEntityManagerProvider $emProvider,
        private readonly ?\App\UseCase\SubscriptionUseCase\ManageSubscriptionUseCase $client = null,
        private readonly ?\App\Abo\Services\AboWaitlistService $waitlist = null) // lot ABO-4
    {
    }

    // ---- équipe ---------------------------------------------------------------------------------------------------------------------------

    /** @return array{slots: list<array<string, mixed>>} */
    public function slots(?int $benefitId, ?string $from, ?string $to): array
    {
        return ['slots' => $this->agenda->slots($benefitId, $from, $to, true)];
    }

    /** @return array{subscriptions: list<array<string, mixed>>} */
    public function toSchedule(): array
    {
        return ['subscriptions' => $this->agenda->toSchedule(true)];
    }

    /** @return array{appointment: array<string, mixed>, subscription: array<string, mixed>} */
    public function teamBook(int $id, array $body, ?string $by, string $locale): array
    {
        $appointment = $this->guard(fn () => $this->agenda->book($this->subscription($id), (int) ($body['benefitId'] ?? 0), self::intOrNull($body['occurrenceId'] ?? null),
            self::intOrNull($body['slotId'] ?? null), isset($body['startsAt']) ? (string) $body['startsAt'] : null, BenefitOccurrence::SOURCE_TEAM, $by));

        return ['appointment' => $appointment, 'subscription' => $this->followUp->subscription($id, $locale)];
    }

    /** @return array{appointment: array<string, mixed>, subscription: array<string, mixed>} */
    public function teamMove(int $id, int $occurrenceId, array $body, ?string $by, string $locale): array
    {
        $appointment = $this->guard(fn () => $this->agenda->move($this->subscription($id), $occurrenceId, self::intOrNull($body['slotId'] ?? null), isset($body['startsAt']) ? (string) $body['startsAt'] : null, BenefitOccurrence::SOURCE_TEAM, $by));

        return ['appointment' => $appointment, 'subscription' => $this->followUp->subscription($id, $locale)];
    }

    /** @return array<string, mixed> la fiche */
    public function teamCancel(int $id, int $occurrenceId, ?string $by, string $locale): array
    {
        $this->guard(fn () => $this->agenda->cancelDate($this->subscription($id), $occurrenceId, BenefitOccurrence::SOURCE_TEAM, $by));

        return $this->followUp->subscription($id, $locale);
    }

    /** @return array{slotId: int, released: int} */
    public function cancelSlot(int $slotId, array $body, ?string $by, string $host, string $locale): array
    {
        return $this->agenda->cancelSlot($slotId, isset($body['reason']) ? (string) $body['reason'] : null, $by, $host, $locale);
    }

    // ---- abonné ---------------------------------------------------------------------------------------------------------------------------

    /** @return array{slots: list<array<string, mixed>>} @throws SubscriptionException */
    public function clientSlots(User $user, int $id, int $benefitId): array
    {
        return $this->clientRefusal(fn () => ['slots' => $this->agenda->clientSlots($this->owned($user, $id)->getPlan() ?? throw new AboException(404, 'SUBSCRIPTION_NOT_FOUND', 'Abonnement introuvable.'), $benefitId, false)]);
    }

    /** Les créneaux d'une formule au choix de la souscription (Q1), avant d'être abonné @return array{slots: list<array<string, mixed>>} @throws SubscriptionException */
    public function planSlots(int $planId, int $benefitId): array
    {
        return $this->clientRefusal(function () use ($planId, $benefitId) {
            $plan = $this->emProvider->getEntityManager()->getRepository(SubscriptionPlan::class)->find($planId);
            if ($plan === null || !$plan->isActive()) {
                throw new AboException(404, 'PLAN_NOT_FOUND', 'Formule introuvable.');
            }

            return ['slots' => $this->agenda->clientSlots($plan, $benefitId, true)];
        });
    }

    /** @return array{appointment: array<string, mixed>, subscription: mixed} @throws SubscriptionException */
    public function clientBook(User $user, int $id, array $body, string $locale): array
    {
        return $this->clientRefusal(function () use ($user, $id, $body, $locale) {
            $appointment = $this->agenda->book($this->owned($user, $id), (int) ($body['benefitId'] ?? 0), null, self::intOrNull($body['slotId'] ?? null) ?? throw new AboException(422, 'SLOT_REQUIRED', 'Choisissez un créneau (slotId).', 'slotId'),
                null, BenefitOccurrence::SOURCE_CLIENT, null);

            return ['appointment' => $appointment, 'subscription' => $this->client?->get($id, $user, $locale)];
        });
    }

    /** @return array{appointment: array<string, mixed>, subscription: mixed} @throws SubscriptionException */
    public function clientMove(User $user, int $id, int $occurrenceId, array $body, string $locale): array
    {
        return $this->clientRefusal(function () use ($user, $id, $occurrenceId, $body, $locale) {
            $appointment = $this->agenda->move($this->owned($user, $id), $occurrenceId, self::intOrNull($body['slotId'] ?? null) ?? throw new AboException(422, 'SLOT_REQUIRED', 'Choisissez un créneau (slotId).', 'slotId'),
                null, BenefitOccurrence::SOURCE_CLIENT, null);

            return ['appointment' => $appointment, 'subscription' => $this->client?->get($id, $user, $locale)];
        });
    }

    /** @return array{cancellation: array{occurrenceId: int, status: string}, subscription: mixed} @throws SubscriptionException */
    public function clientCancel(User $user, int $id, int $occurrenceId, string $locale): array
    {
        return $this->clientRefusal(function () use ($user, $id, $occurrenceId, $locale) {
            $r = $this->agenda->cancelDate($this->owned($user, $id), $occurrenceId, BenefitOccurrence::SOURCE_CLIENT, null);

            return ['cancellation' => $r, 'subscription' => $this->client?->get($id, $user, $locale)];
        });
    }

    // ---- lot ABO-4 : liste d'attente --------------------------------------------------------------------------------------------------------

    /** @return array{slotId: int, freePlaces: int, waitlist: list<array<string, mixed>>} */
    public function slotWaitlist(int $slotId): array
    {
        return $this->waitlistService()->forSlot($slotId, true);
    }

    /** @return array{wait: array<string, mixed>} */
    public function proposeWait(int $waitId, ?string $by, string $host): array
    {
        return ['wait' => $this->guard(fn () => $this->waitlistService()->propose($waitId, $by, $host))];
    }

    /** @return array{waitlist: list<array<string, mixed>>} @throws SubscriptionException */
    public function myWaits(User $user, int $id): array
    {
        return $this->clientRefusal(fn () => ['waitlist' => $this->waitlistService()->mine($this->owned($user, $id))]);
    }

    /** @return array{wait: array<string, mixed>} @throws SubscriptionException */
    public function joinWait(User $user, int $id, int $slotId, string $host): array
    {
        return $this->clientRefusal(fn () => ['wait' => $this->waitlistService()->join($this->owned($user, $id), $slotId, $host)]);
    }

    /** @return array{left: true} @throws SubscriptionException */
    public function leaveWait(User $user, int $id, int $slotId, string $host): array
    {
        return $this->clientRefusal(function () use ($user, $id, $slotId, $host) {
            $this->waitlistService()->leave($this->owned($user, $id), $slotId, $host);

            return ['left' => true];
        });
    }

    /** @return array{appointment: array<string, mixed>, subscription: mixed} @throws SubscriptionException */
    public function acceptWait(User $user, int $id, int $waitId, string $host, string $locale): array
    {
        return $this->clientRefusal(fn () => ['appointment' => $this->waitlistService()->accept($this->owned($user, $id), $waitId, $host), 'subscription' => $this->client?->get($id, $user, $locale)]);
    }

    private function waitlistService(): \App\Abo\Services\AboWaitlistService
    {
        return $this->waitlist ?? throw new AboException(503, 'WAITLIST_UNAVAILABLE', 'La liste d\'attente n\'est pas disponible.');
    }

    // ---- interne ----------------------------------------------------------------------------------------------------------------------------

    private function subscription(int $id): Subscription
    {
        return $this->emProvider->getEntityManager()->getRepository(Subscription::class)->find($id) ?? throw new AboException(404, 'SUBSCRIPTION_NOT_FOUND', 'Abonnement introuvable.');
    }

    /** Un abonnement d'un autre client : 404, comme toutes les routes de l'abonné */
    private function owned(User $user, int $id): Subscription
    {
        $s = $this->subscription($id);
        if ($s->getUser()?->getId() !== $user->getId()) {
            throw new AboException(404, 'SUBSCRIPTION_NOT_FOUND', 'Abonnement introuvable.');
        }

        return $s;
    }

    /** Un abonnement tenu par un autre traitement : 409 SUBSCRIPTION_BUSY, jamais une erreur 500 */
    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (SubscriptionException $e) {
            throw new AboException($e->getStatusCode(), $e->code(), $e->getMessage(), previous: $e);
        } catch (SubscriptionLockTimeout) {
            throw new AboException(409, 'SUBSCRIPTION_BUSY', 'Cet abonnement est en cours de traitement : réessayez dans un instant.');
        }
    }

    /** Côté abonné : le refus a la forme des autres routes `/api/subscriptions` @throws SubscriptionException */
    private function clientRefusal(callable $fn): mixed
    {
        try {
            return $this->guard($fn);
        } catch (AboException $e) {
            throw new SubscriptionException($e->status, $e->getMessage(), $e->field !== null ? [SubscriptionException::error($e->field, $e->errorCode, $e->getMessage())] : [], $e->details, $e->errorCode, $e);
        }
    }

    private static function intOrNull(mixed $v): ?int
    {
        return is_int($v) ? $v : (is_string($v) && ctype_digit($v) ? (int) $v : null);
    }
}
