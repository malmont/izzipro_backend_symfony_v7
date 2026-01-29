<?php

namespace App\Tests\UseCase\StockValue;

use App\Services\StockEvolutionService\StockValueService;
use App\UseCase\StockValue\GetStockValueForLastMonthUseCase;
use PHPUnit\Framework\TestCase;

class GetStockValueForLastMonthUseCaseTest extends TestCase
{
    public function testExecuteReturnsStockValue()
    {
        // Arrange
        $stockValueService = $this->createMock(StockValueService::class);
        $expectedValue = ['last_month' => 1200];

        $stockValueService->expects($this->once())
            ->method('getStockValueForLastMonth')
            ->willReturn($expectedValue);

        $useCase = new GetStockValueForLastMonthUseCase($stockValueService);

        // Act
        $result = $useCase->execute();

        // Assert
        $this->assertEquals($expectedValue, $result);
    }
}
