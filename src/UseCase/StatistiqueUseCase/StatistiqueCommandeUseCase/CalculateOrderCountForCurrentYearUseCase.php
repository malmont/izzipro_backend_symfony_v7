<?php

namespace App\UseCase\StatistiqueUseCase\StatistiqueCommandeUseCase;

use App\Services\StatistiqueService\StatistiqueCommandeService;

class CalculateOrderCountForCurrentYearUseCase
{
    private $statistiqueCommandeService;

    public function __construct(StatistiqueCommandeService $statistiqueCommandeService)
    {
        $this->statistiqueCommandeService = $statistiqueCommandeService;
    }

    public function execute(): int
    {
        return $this->statistiqueCommandeService->getOrderCountForCurrentYear();
    }
}
