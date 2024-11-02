<?php

namespace App\UseCase\StockValue;

use App\Services\StockEvolutionService\StockValueService;

class GetStockValueForCurrentUseCase
{
    private StockValueService $stockValueService;

    public function __construct(StockValueService $stockValueService)
    {
        $this->stockValueService = $stockValueService;
    }

    /**
     * Récupère la valeur de stock pour le mois actuel avec formatage.
     *
     * @return array
     */
    public function execute(): array
    {
        return $this->stockValueService->getStockValueCurrentMonth();
    }
}
