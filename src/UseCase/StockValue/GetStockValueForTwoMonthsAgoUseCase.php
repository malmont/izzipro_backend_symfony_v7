<?php

namespace App\UseCase\StockValue;

use App\Services\StockEvolutionService\StockValueService;

class GetStockValueForTwoMonthsAgoUseCase
{
    private $stockValueService;

    public function __construct(StockValueService $stockValueService)
    {
        $this->stockValueService = $stockValueService;
    }

    public function execute(): float
    {
        return $this->stockValueService->getStockValueForTwoMonthsAgo();
    }
}