<?php

namespace App\Tests\UseCase\StockValue;

use App\Services\StockEvolutionService\StockValueService;
use App\UseCase\StockValue\GetStockValueForTwoMonthsAgoUseCase;
use PHPUnit\Framework\TestCase;

class GetStockValueForTwoMonthsAgoUseCaseTest extends TestCase
{
    public function testExecuteReturnsStockValue()
    {
        // Arrange
        $stockValueService = $this->createMock(StockValueService::class);
        $expectedValue = ['two_months_ago' => 800];

        $stockValueService->expects($this->once())
            ->method('getStockValueForTwoMonthsAgo')
            ->willReturn($expectedValue);

        $useCase = new GetStockValueForTwoMonthsAgoUseCase($stockValueService);

        // Act
        $result = $useCase->execute();

        // Assert
        $this->assertEquals($expectedValue, $result);
    }
}
