<?php

namespace App\UseCase\StatistiqueUseCase\StatistiquePanierUseCase;

use App\Services\StatistiqueService\StatistiquePanierService;

class CalculateWeeklyAverageOrderValueForCurrentMonthUseCase
{
    private $statistiquePanierService;

    public function __construct(StatistiquePanierService $statistiquePanierService)
    {
        $this->statistiquePanierService = $statistiquePanierService;
    }

    public function execute(?int $orderSource = null): array
    {
        return $this->statistiquePanierService->getWeeklyAverageOrderValueForCurrentMonth($orderSource);
    }
}
