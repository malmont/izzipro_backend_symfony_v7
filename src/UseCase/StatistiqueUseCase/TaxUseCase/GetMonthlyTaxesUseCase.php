<?php

namespace App\UseCase\StatistiqueUseCase\TaxUseCase;

use App\Services\StatistiqueService\SalesStatisticsService;

class GetMonthlyTaxesUseCase
{

    public function __construct(private readonly SalesStatisticsService $statistics) // lot G3 (09/11/2026)
    {
    }

    /**
     * Exécute le UseCase pour obtenir les taxes mensuelles pour l'année en cours.
     *
     * @return array
     */
    public function execute(): array
    {
        return $this->statistics->taxesMonthly();
    }
}
