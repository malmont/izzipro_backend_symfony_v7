<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\GestionFixedCostService;
use App\Gestion\Services\PeriodCalendar;

/** Frais fixes mensuels (lot FF1, `docs/frais-fixes-gestion.md`) : liste, équivalent mensuel, total par catégorie */
class GestionFixedCostUseCase
{
    public function __construct(private readonly GestionFixedCostService $fixedCosts, private readonly PeriodCalendar $calendar)
    {
    }

    public function list(bool $all, string $locale): array
    {
        return $this->fixedCosts->list($all, $locale, $this->calendar->today());
    }
}
