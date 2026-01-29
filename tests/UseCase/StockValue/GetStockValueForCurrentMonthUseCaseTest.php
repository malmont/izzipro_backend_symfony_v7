<?php

namespace App\Tests\UseCase\StockValue;

use App\Services\StockEvolutionService\StockValueService;
use App\UseCase\StockValue\GetStockValueForCurrentMonthUseCase;
use PHPUnit\Framework\TestCase;

class GetStockValueForCurrentMonthUseCaseTest extends TestCase
{
    public function testExecuteReturnsStockValue()
    {
        // Arrange
        $stockValueService = $this->createMock(StockValueService::class);
        $expectedValue = ['current_month' => 1000];

        $stockValueService->expects($this->once())
            ->method('getStockValueForCurrentMonth')
            ->willReturn($expectedValue);

        $useCase = new GetStockValueForCurrentMonthUseCase($stockValueService);

        // Act
        $result = $useCase->execute();

        // Assert
        $this->assertEquals($expectedValue, $result);
    }
}
