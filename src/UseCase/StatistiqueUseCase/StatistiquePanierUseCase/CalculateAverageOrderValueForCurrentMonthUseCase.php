<?php

namespace App\UseCase\StatistiqueUseCase\StatistiquePanierUseCase;

use App\Services\StatistiqueService\StatistiquePanierService;

class CalculateAverageOrderValueForCurrentMonthUseCase
{
    private $statistiquePanierService;

    public function __construct(StatistiquePanierService $statistiquePanierService)
    {
        $this->statistiquePanierService = $statistiquePanierService;
    }

    public function execute(?int $orderSource = null): float
    {
        return $this->statistiquePanierService->getAverageOrderValueForCurrentMonth($orderSource);
    }
}
