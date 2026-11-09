<?php
namespace App\UseCase\StatistiqueUseCase\StatistiqueCarrierUseCase;

use App\Services\StatistiqueService\SalesStatisticsService;

class GetMonthlyCarrierStatisticsUseCase
{

    public function __construct(private readonly SalesStatisticsService $statistics) // lot G3 (09/11/2026) : une requête agrégée, fuseau du magasin
    {
    }

    public function execute(): array
    {
        return $this->statistics->carrierMonthly();
    }
}
