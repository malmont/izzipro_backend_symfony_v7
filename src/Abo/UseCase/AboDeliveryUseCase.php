<?php

namespace App\Abo\UseCase;

use App\Abo\Services\AboDeliveryService;
use App\Abo\Services\AboException;
use App\Abo\Services\AboFollowUpService;
use App\Entity\Subscription;
use App\Entity\User;
use App\Services\SubscriptionService\SubscriptionException;
use App\Services\SubscriptionService\SubscriptionLockTimeout;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * « Fait » et suivi des droits (lot ABO-3a, 20/11/2026) :
 * - l'ÉQUIPE (gestion web et application de gestion mobile, `ROLE_ADMIN`, module Boutique) : « À faire aujourd'hui », fait / absent,
 *   séance entière, correction d'un constat, contestations et leur décision, photo de preuve ;
 * - l'ABONNÉ (boutique web et commerce mobile) : son avis (confirmer, contester), la photo, couper le courriel « fait ».
 * Chaque geste de l'équipe sur un abonnement rend la fiche à jour.
 */
class AboDeliveryUseCase
{
    public function __construct(private readonly AboDeliveryService $delivery, private readonly AboFollowUpService $followUp, private readonly TenantEntityManagerProvider $emProvider,
        private readonly ?\App\UseCase\SubscriptionUseCase\ManageSubscriptionUseCase $client = null)
    {
    }

    // ---- équipe ---------------------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function today(?string $date, string $locale): array
    {
        return $this->delivery->today($date, true, $locale);
    }

    /** @return array{contestations: list<array<string, mixed>>} */
    public function contestations(string $locale): array
    {
        return ['contestations' => $this->delivery->contestations(true, $locale)];
    }

    /** @return array{occurrence: array<string, mixed>, changed: bool, subscription: array<string, mixed>} */
    public function markDone(int $id, int $occurrenceId, ?string $note, ?UploadedFile $photo, ?string $by, string $host, string $locale): array
    {
        $r = $this->guard(fn () => $this->delivery->markDone($this->subscription($id), $occurrenceId, $note, $photo, $by, $host, $locale));

        return $r + ['subscription' => $this->followUp->subscription($id, $locale)];
    }

    /** @return array{occurrence: array<string, mixed>, changed: bool, subscription: array<string, mixed>} */
    public function markMissed(int $id, int $occurrenceId, array $body, ?string $by, string $locale): array
    {
        $r = $this->guard(fn () => $this->delivery->markMissed($this->subscription($id), $occurrenceId, self::text($body['note'] ?? null), $by, $locale));

        return $r + ['subscription' => $this->followUp->subscription($id, $locale)];
    }

    /** @return array<string, mixed> */
    public function markSession(int $slotId, array $body, ?string $by, string $host, string $locale): array
    {
        return $this->guard(fn () => $this->delivery->markSessionDone($slotId, $body['absentSubscriptionIds'] ?? [], self::text($body['note'] ?? null), $by, $host, $locale));
    }

    /** @return array{occurrence: array<string, mixed>, subscription: array<string, mixed>} */
    public function undo(int $id, int $occurrenceId, array $body, ?string $by, string $locale): array
    {
        $occurrence = $this->guard(fn () => $this->delivery->undo($this->subscription($id), $occurrenceId, self::text($body['reason'] ?? null), $by, $locale));

        return ['occurrence' => $occurrence, 'subscription' => $this->followUp->subscription($id, $locale)];
    }

    /** @return array{occurrence: array<string, mixed>, subscription: array<string, mixed>} */
    public function resolve(int $id, int $occurrenceId, array $body, ?string $by, string $locale): array
    {
        $occurrence = $this->guard(fn () => $this->delivery->resolve($this->subscription($id), $occurrenceId, $body['decision'] ?? null, self::text($body['note'] ?? null), $by, $locale));

        return ['occurrence' => $occurrence, 'subscription' => $this->followUp->subscription($id, $locale)];
    }

    /** @return array{path: string, mime: string, sha256: ?string} */
    public function teamProof(int $id, int $occurrenceId): array
    {
        return $this->delivery->proofFile($this->subscription($id), $occurrenceId);
    }

    // ---- abonné ---------------------------------------------------------------------------------------------------------------------------

    /** @return array{acknowledgement: array<string, mixed>, subscription: mixed} @throws SubscriptionException */
    public function acknowledge(User $user, int $id, int $occurrenceId, array $body, string $locale): array
    {
        return $this->clientRefusal(function () use ($user, $id, $occurrenceId, $body, $locale) {
            $r = $this->delivery->acknowledge($this->owned($user, $id), $user, $occurrenceId, $body['decision'] ?? null, $body['comment'] ?? null, $locale);

            return ['acknowledgement' => $r, 'subscription' => $this->client?->get($id, $user, $locale)];
        });
    }

    /** @return array{path: string, mime: string, sha256: ?string} @throws SubscriptionException */
    public function clientProof(User $user, int $id, int $occurrenceId): array
    {
        return $this->clientRefusal(fn () => $this->delivery->proofFile($this->owned($user, $id), $occurrenceId));
    }

    /** @return array{doneEmails: bool} @throws SubscriptionException */
    public function setDoneEmails(User $user, int $id, array $body): array
    {
        return $this->clientRefusal(fn () => $this->delivery->setEmails($this->owned($user, $id), $body)); // lot ABO-4 : `reminders` aussi
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

    private static function text(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
    }
}
