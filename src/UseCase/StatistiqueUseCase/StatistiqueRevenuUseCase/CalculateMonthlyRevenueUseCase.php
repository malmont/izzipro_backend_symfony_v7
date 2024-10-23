<?php

namespace App\UseCase\StatistiqueUseCase\StatistiqueRevenuUseCase;

use App\Services\StatistiqueService\StatistiqueRevenuService;

class CalculateMonthlyRevenueUseCase
{
    private $statistiqueRevenuService;

    public function __construct(StatistiqueRevenuService $statistiqueRevenuService)
    {
        $this->statistiqueRevenuService = $statistiqueRevenuService;
    }

    public function execute(int $monthsAgo): float
    {
        return $this->statistiqueRevenuService->getRevenueForMonth($monthsAgo);
    }
}
