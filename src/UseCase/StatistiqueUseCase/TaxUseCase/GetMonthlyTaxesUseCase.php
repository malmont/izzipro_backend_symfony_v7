<?php

namespace App\UseCase\StatistiqueUseCase\TaxUseCase;

use App\Services\StatistiqueService\TaxService;

class GetMonthlyTaxesUseCase
{
    private $taxService;

    public function __construct(TaxService $taxService)
    {
        $this->taxService = $taxService;
    }

    /**
     * Exécute le UseCase pour obtenir les taxes mensuelles pour l'année en cours.
     *
     * @return array
     */
    public function execute(): array
    {
        $year = (int)date('Y');
        return $this->taxService->getMonthlyTaxesForYear($year);
    }
}
