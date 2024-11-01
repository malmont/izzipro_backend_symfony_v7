<?php

namespace App\UseCase\StatistiqueUseCase\StatistiquePanierUseCase;

use App\Services\StatistiqueService\StatistiquePanierService;

class CalculateAverageOrderValueForCurrentYearUseCase
{
    private $statistiquePanierService;

    public function __construct(StatistiquePanierService $statistiquePanierService)
    {
        $this->statistiquePanierService = $statistiquePanierService;
    }

    public function execute(?int $orderSource = null): float
    {
        return $this->statistiquePanierService->getAverageOrderValueForCurrentYear($orderSource);
    }
}
