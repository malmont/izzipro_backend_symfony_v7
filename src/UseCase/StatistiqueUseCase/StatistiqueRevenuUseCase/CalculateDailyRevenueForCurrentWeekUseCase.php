<?php

namespace App\UseCase\StatistiqueUseCase\StatistiqueRevenuUseCase;

use App\Services\StatistiqueService\StatistiqueRevenuService;

class CalculateDailyRevenueForCurrentWeekUseCase
{
    private $statistiqueRevenuService;

    public function __construct(StatistiqueRevenuService $statistiqueRevenuService)
    {
        $this->statistiqueRevenuService = $statistiqueRevenuService;
    }

    public function execute(?int $orderSource = null): array
    {
        return $this->statistiqueRevenuService->getDailyRevenueForCurrentWeek($orderSource);
    }
}
