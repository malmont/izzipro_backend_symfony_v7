<?php

namespace App\UseCase\StatistiqueUseCase\StatistiqueRevenuUseCase;

use App\Services\StatistiqueService\StatistiqueRevenuService;
use DateTime;

class CalculateCustomIntervalRevenueUseCase
{
    private $statistiqueRevenuService;

    public function __construct(StatistiqueRevenuService $statistiqueRevenuService)
    {
        $this->statistiqueRevenuService = $statistiqueRevenuService;
    }

    public function execute(DateTime $startDate, DateTime $endDate,?int $orderSource = null): float
    {
        return $this->statistiqueRevenuService->getRevenueForCustomInterval($startDate, $endDate, $orderSource);
    }
}
