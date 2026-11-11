<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\GestionCashService;
use App\Gestion\Services\GestionReconciliationService;
use App\Gestion\Services\GestionValidationException;

/** Trésorerie saisie (lot B1b-1, `docs/tresorerie-gestion.md`) : projection, soldes, mouvements prévus, réglages ; rapprochement (lot RAP). */
class GestionCashUseCase
{
    public function __construct(private readonly GestionCashService $cash, private readonly GestionReconciliationService $reconciliation)
    {
    }

    public function projection(mixed $days, string $locale): array
    {
        $n = $days === null || $days === '' ? GestionCashService::DEFAULT_DAYS : (is_string($days) && ctype_digit($days) ? (int) $days : null);
        if ($n === null) {
            throw new GestionValidationException(['days' => 'MUST_BE_INTEGER']);
        }
        if ($n < GestionCashService::MIN_DAYS || $n > GestionCashService::MAX_DAYS) {
            throw new GestionValidationException(['days' => 'OUT_OF_RANGE']);
        }

        return $this->cash->projection($n, $locale) + ['reconciliation' => $this->reconciliation->summary()];
    }

    public function balances(int $limit): array
    {
        return $this->cash->balances($limit);
    }

    public function saveBalance(array $body, ?string $by): array
    {
        $out = $this->cash->saveBalance($body, $by);

        return $out + ['reconciliation' => $this->reconciliation->afterSave($out['id'])];
    }

    public function reconciliations(int $limit): array
    {
        return $this->reconciliation->list($limit);
    }

    public function decideGap(int $id, array $body, ?string $by): array
    {
        return $this->reconciliation->decide($id, $body, $by);
    }

    public function updateBalance(int $id, array $body): array
    {
        return $this->cash->updateBalance($id, $body);
    }

    public function deleteBalance(int $id): void
    {
        $this->cash->deleteBalance($id);
    }

    public function movements(?string $category, string $locale, bool $detailSalaries): array
    {
        return $this->cash->movements($category === '' ? null : $category, $locale, $detailSalaries);
    }

    public function createMovement(array $body, ?string $by, string $locale): array
    {
        return $this->cash->createMovement($body, $by, $locale);
    }

    public function updateMovement(int $id, array $body, string $locale): array
    {
        return $this->cash->updateMovement($id, $body, $locale);
    }

    public function deleteMovement(int $id, string $locale): void
    {
        $this->cash->deleteMovement($id, $locale);
    }

    public function settings(): array
    {
        return $this->cash->settings();
    }

    public function putSettings(array $body): array
    {
        return $this->cash->putSettings($body);
    }
}
