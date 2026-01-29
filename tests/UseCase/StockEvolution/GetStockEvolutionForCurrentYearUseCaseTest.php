<?php

namespace App\Tests\UseCase\StockEvolution;

use App\Services\StockEvolutionService\StockEvolutionService;
use App\UseCase\StockEvolution\GetStockEvolutionForCurrentYearUseCase;
use PHPUnit\Framework\TestCase;

class GetStockEvolutionForCurrentYearUseCaseTest extends TestCase
{
    public function testExecuteReturnsStockEvolution()
    {
        // Arrange
        $stockEvolutionService = $this->createMock(StockEvolutionService::class);
        $expectedValue = ['evolution' => 'declining'];

        $stockEvolutionService->expects($this->once())
            ->method('getStockEvolutionForCurrentYear')
            ->willReturn($expectedValue);

        $useCase = new GetStockEvolutionForCurrentYearUseCase($stockEvolutionService);

        // Act
        $result = $useCase->execute();

        // Assert
        $this->assertEquals($expectedValue, $result);
    }
}
