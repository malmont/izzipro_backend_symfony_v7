<?php

namespace App\Abo\UseCase;

use App\Abo\Services\AboException;
use App\Abo\Services\AboFollowUpService;
use App\Abo\Services\AboStaffService;
use App\Abo\Services\AboStaffWorkService;
use App\Entity\Subscription;
use App\Entity\User;
use App\Services\SubscriptionService\SubscriptionException;
use App\Services\SubscriptionService\SubscriptionLockTimeout;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Intervenants des abonnements (lot ABO-3b, 20/11/2026) :
 * - l'ÉQUIPE (`ROLE_ADMIN`, module Boutique) : la liste, l'invitation, le retrait, l'intervenant d'une date ;
 * - l'INTERVENANT (`ROLE_STAFF_PROVIDER`, module Boutique) : ses dates du jour et de la semaine, « fait », « absent », sa séance entière,
 *   la photo de ses dates. Rien d'autre.
 */
class AboStaffUseCase
{
    public function __construct(private readonly AboStaffService $staff, private readonly AboStaffWorkService $work, private readonly AboFollowUpService $followUp,
        private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    // ---- équipe ---------------------------------------------------------------------------------------------------------------------------

    /** @return array{staff: list<array<string, mixed>>} */
    public function list(): array
    {
        return ['staff' => $this->staff->list()];
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function invite(array $body, User $by): array
    {
        return $this->staff->invite($body, $by);
    }

    /** @return array{userId: int, unassigned: int} */
    public function revoke(int $userId): array
    {
        return $this->staff->revoke($userId);
    }

    /** @param array<string, mixed> $body `{ staffUserId: int | null }` @return array{assignment: array<string, mixed>, subscription: array<string, mixed>} */
    public function assign(int $id, int $occurrenceId, array $body, ?string $by, string $locale): array
    {
        if (!array_key_exists('staffUserId', $body)) {
            throw new AboException(422, 'STAFF_INVALID', 'Intervenant : un compte qui porte le rôle d\'intervenant, ou null.', 'staffUserId');
        }
        $assignment = $this->guard(fn () => $this->staff->assign($this->subscription($id), $occurrenceId, $body['staffUserId'], $by));

        return ['assignment' => $assignment, 'subscription' => $this->followUp->subscription($id, $locale)];
    }

    // ---- intervenant ------------------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function day(User $staff, ?string $date, string $locale): array
    {
        return $this->work->day($staff, $date, $locale);
    }

    /** @return array<string, mixed> */
    public function week(User $staff, ?string $from, string $locale): array
    {
        return $this->work->week($staff, $from, $locale);
    }

    /** @return array{occurrence: array<string, mixed>, changed: bool} */
    public function markDone(User $staff, int $occurrenceId, ?string $note, ?UploadedFile $photo, string $host, string $locale): array
    {
        return $this->guard(fn () => $this->work->markDone($staff, $occurrenceId, $note, $photo, $host, $locale));
    }

    /** @param array<string, mixed> $body @return array{occurrence: array<string, mixed>, changed: bool} */
    public function markMissed(User $staff, int $occurrenceId, array $body, string $locale): array
    {
        return $this->guard(fn () => $this->work->markMissed($staff, $occurrenceId, self::text($body['note'] ?? null), $locale));
    }

    /** @param array<string, mixed> $body `{ absentOccurrenceIds?: [], note? }` @return array<string, mixed> */
    public function markSession(User $staff, int $slotId, array $body, string $host, string $locale): array
    {
        return $this->guard(fn () => $this->work->markSession($staff, $slotId, $body['absentOccurrenceIds'] ?? [], self::text($body['note'] ?? null), $host, $locale));
    }

    /** @return array{path: string, mime: string, sha256: ?string} */
    public function proof(User $staff, int $occurrenceId): array
    {
        return $this->work->proof($staff, $occurrenceId);
    }

    // ---- interne ----------------------------------------------------------------------------------------------------------------------------

    private function subscription(int $id): Subscription
    {
        return $this->emProvider->getEntityManager()->getRepository(Subscription::class)->find($id) ?? throw new AboException(404, 'SUBSCRIPTION_NOT_FOUND', 'Abonnement introuvable.');
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

    private static function text(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
    }
}
