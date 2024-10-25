<?php
namespace App\UseCase\StatistiqueUseCase\StatistiqueCarrierUseCase;

use App\Services\StatistiqueService\CarrierStatisticsService;

class GetMonthlyCarrierStatisticsUseCase
{
    private $carrierStatisticsService;

    public function __construct(CarrierStatisticsService $carrierStatisticsService)
    {
        $this->carrierStatisticsService = $carrierStatisticsService;
    }

    public function execute(): array
    {
        return $this->carrierStatisticsService->getMonthlyCarrierStatisticsForCurrentYear();
    }
}
