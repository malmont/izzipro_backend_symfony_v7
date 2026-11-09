<?php

namespace App\UseCase\StatistiqueUseCase\SalesStatisticsUseCase;

use App\Services\StatistiqueService\SalesStatisticsService;

/** Statistiques de ventes : panier moyen (lot G3, 09/11/2026), une seule réponse pour toute la route */
class GetAverageOrderValueStatisticsUseCase
{
    public function __construct(private readonly SalesStatisticsService $statistics)
    {
    }

    /** @return array<string, mixed> */
    public function execute(?int $orderSource = null): array
    {
        return $this->statistics->averageOrderValueOverview($orderSource);
    }
}
