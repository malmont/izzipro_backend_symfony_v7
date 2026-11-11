<?php

namespace App\Gestion\UseCase;

use App\Entity\Collections;
use App\Gestion\Services\GestionAdviceService;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Gestion\Services\PeriodCalendar;
use App\Services\TenantEntityManagerProvider;

/** Guide de la gestion (lot G1a, `docs/guide-ia-gestion.md`) : liste, relance, fait, écarté. */
class GestionAdviceUseCase
{
    public function __construct(private readonly GestionAdviceService $advices, private readonly PeriodCalendar $calendar, private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    public function list(mixed $periodId, mixed $status, string $locale): array
    {
        $status = $status === null || $status === '' ? 'new' : $status;
        if (!in_array($status, ['new', 'all'], true)) {
            throw new GestionValidationException(['status' => 'INVALID_STATUS']);
        }

        return $this->advices->list($this->period($periodId), $status, $locale);
    }

    public function refresh(array $body, ?string $user): array
    {
        return $this->advices->refresh($this->period($body['periodId'] ?? null), $user);
    }

    public function setStatus(int $id, string $status, array $body, ?string $user, string $locale): array
    {
        return $this->advices->setStatus($id, $status, $user, $body['note'] ?? ($body['reason'] ?? null), $locale, $body['applied'] ?? null);
    }

    public function measure(int $id, string $locale): array
    {
        return $this->advices->measure($id, $locale);
    }

    private function period(mixed $id): Collections
    {
        if ($id === null || $id === '') {
            return $this->calendar->current();
        }
        if (!(is_int($id) || (is_string($id) && ctype_digit($id)))) {
            throw new GestionValidationException(['periodId' => 'INVALID_ID']);
        }
        $period = $this->emProvider->getEntityManager()->find(Collections::class, (int) $id);
        if ($period === null) {
            throw new GestionConflictException('PERIOD_NOT_FOUND', 'Période introuvable.', 404, ['periodId' => (int) $id]);
        }

        return $period;
    }
}
