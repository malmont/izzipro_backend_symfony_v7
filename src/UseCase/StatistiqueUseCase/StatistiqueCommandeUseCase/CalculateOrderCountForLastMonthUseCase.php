<?php

namespace App\UseCase\StatistiqueUseCase\StatistiqueCommandeUseCase;

use App\Services\StatistiqueService\StatistiqueCommandeService;

class CalculateOrderCountForLastMonthUseCase
{
    private $statistiqueCommandeService;

    public function __construct(StatistiqueCommandeService $statistiqueCommandeService)
    {
        $this->statistiqueCommandeService = $statistiqueCommandeService;
    }

    public function execute(?int $orderSource = null): int
    {
        return $this->statistiqueCommandeService->getOrderCountForLastMonth($orderSource);
    }
}
