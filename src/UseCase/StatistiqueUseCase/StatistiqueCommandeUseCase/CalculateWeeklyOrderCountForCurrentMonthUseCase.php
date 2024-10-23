<?php

namespace App\UseCase\StatistiqueUseCase\StatistiqueCommandeUseCase;

use App\Services\StatistiqueService\StatistiqueCommandeService;

class CalculateWeeklyOrderCountForCurrentMonthUseCase
{
    private $statistiqueCommandeService;

    public function __construct(StatistiqueCommandeService $statistiqueCommandeService)
    {
        $this->statistiqueCommandeService = $statistiqueCommandeService;
    }

    public function execute(): array
    {
        return $this->statistiqueCommandeService->getWeeklyOrderCountForCurrentMonth();
    }
}
