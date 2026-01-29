<?php

namespace App\Tests\UseCase\StockEvolution;

use App\Services\StockEvolutionService\StockEvolutionService;
use App\UseCase\StockEvolution\GetStockEvolutionForCurrentWeekUseCase;
use PHPUnit\Framework\TestCase;

class GetStockEvolutionForCurrentWeekUseCaseTest extends TestCase
{
    public function testExecuteReturnsStockEvolution()
    {
        // Arrange
        $stockEvolutionService = $this->createMock(StockEvolutionService::class);
        $expectedValue = ['evolution' => 'stable'];

        $stockEvolutionService->expects($this->once())
            ->method('getStockEvolutionForCurrentWeek')
            ->willReturn($expectedValue);

        $useCase = new GetStockEvolutionForCurrentWeekUseCase($stockEvolutionService);

        // Act
        $result = $useCase->execute();

        // Assert
        $this->assertEquals($expectedValue, $result);
    }
}
