<?php

namespace App\UseCase\StatistiqueUseCase\SalesStatisticsUseCase;

use App\Services\StatistiqueService\SalesStatisticsService;

/** Statistiques de ventes : chiffre d'affaires (lot G3, 09/11/2026), une seule réponse pour toute la route */
class GetRevenueStatisticsUseCase
{
    public function __construct(private readonly SalesStatisticsService $statistics)
    {
    }

    /** @return array<string, mixed> */
    public function execute(?int $orderSource = null): array
    {
        return $this->statistics->revenueOverview($orderSource);
    }

    /** Chiffre d'affaires d'un intervalle de jours du magasin (`?startDate=&endDate=`), cents */
    public function customInterval(\DateTimeInterface $start, \DateTimeInterface $end, ?int $orderSource = null): int
    {
        return $this->statistics->customIntervalRevenue($start, $end, $orderSource);
    }
}
