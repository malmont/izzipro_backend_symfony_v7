<?php

namespace App\UseCase\StatistiqueUseCase\StatistiquePanierUseCase;

use App\Services\StatistiqueService\StatistiquePanierService;

class CalculateMonthlyAverageOrderValueForCurrentYearUseCase
{
    private $statistiquePanierService;

    public function __construct(StatistiquePanierService $statistiquePanierService)
    {
        $this->statistiquePanierService = $statistiquePanierService;
    }

    public function execute(): array
    {
        return $this->statistiquePanierService->getMonthlyAverageOrderValueForCurrentYear();
    }
}
