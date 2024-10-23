<?php

namespace App\UseCase\StockEvolution;

use App\Services\StockEvolutionService\StockEvolutionService;

class GetStockEvolutionForCurrentWeekUseCase
{
    private $stockEvolutionService;

    public function __construct(StockEvolutionService $stockEvolutionService)
    {
        $this->stockEvolutionService = $stockEvolutionService;
    }

    public function execute(): array
    {
        return $this->stockEvolutionService->getStockEvolutionForCurrentWeek();
    }
}
