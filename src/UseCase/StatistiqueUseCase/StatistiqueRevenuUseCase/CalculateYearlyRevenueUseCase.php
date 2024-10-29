<?php

namespace App\UseCase\StatistiqueUseCase\StatistiqueRevenuUseCase;

use App\Services\StatistiqueService\StatistiqueRevenuService;

class CalculateYearlyRevenueUseCase
{
    private $statistiqueRevenuService;

    public function __construct(StatistiqueRevenuService $statistiqueRevenuService)
    {
        $this->statistiqueRevenuService = $statistiqueRevenuService;
    }

    public function execute(int $yearsAgo, ?int $orderSource = null): float
    {
        return $this->statistiqueRevenuService->getRevenueForYear($yearsAgo, $orderSource);
    }
}
