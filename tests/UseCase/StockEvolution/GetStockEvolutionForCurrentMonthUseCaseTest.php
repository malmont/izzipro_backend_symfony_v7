<?php

namespace App\Tests\UseCase\StockEvolution;

use App\Services\StockEvolutionService\StockEvolutionService;
use App\UseCase\StockEvolution\GetStockEvolutionForCurrentMonthUseCase;
use PHPUnit\Framework\TestCase;

class GetStockEvolutionForCurrentMonthUseCaseTest extends TestCase
{
    public function testExecuteReturnsStockEvolution()
    {
        // Arrange
        $stockEvolutionService = $this->createMock(StockEvolutionService::class);
        $expectedValue = ['evolution' => 'increasing'];

        $stockEvolutionService->expects($this->once())
            ->method('getStockEvolutionForCurrentMonth')
            ->willReturn($expectedValue);

        $useCase = new GetStockEvolutionForCurrentMonthUseCase($stockEvolutionService);

        // Act
        $result = $useCase->execute();

        // Assert
        $this->assertEquals($expectedValue, $result);
    }
}
