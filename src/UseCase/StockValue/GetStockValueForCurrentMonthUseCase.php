<?php

namespace App\UseCase\StockValue;

use App\Services\StockEvolutionService\StockValueService;

class GetStockValueForCurrentMonthUseCase
{
    private $stockValueService;

    public function __construct(StockValueService $stockValueService)
    {
        $this->stockValueService = $stockValueService;
    }

    public function execute(): array
    {
        return $this->stockValueService->getStockValueForCurrentMonth();
    }
}
