<?php

namespace App\UseCase\StatistiqueUseCase\StatistiqueCommandeUseCase;

use App\Services\StatistiqueService\StatistiqueCommandeService;

class CalculateMonthlyOrderCountForCurrentYearUseCase
{
    private $statistiqueCommandeService;

    public function __construct(StatistiqueCommandeService $statistiqueCommandeService)
    {
        $this->statistiqueCommandeService = $statistiqueCommandeService;
    }

    public function execute(?int $orderSource = null): array
    {
        return $this->statistiqueCommandeService->getMonthlyOrderCountForCurrentYear( $orderSource);
    }
}