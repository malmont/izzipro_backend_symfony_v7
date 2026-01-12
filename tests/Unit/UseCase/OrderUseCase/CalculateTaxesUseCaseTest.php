<?php

namespace App\Tests\Unit\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Services\OrderService\TaxCalculationService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\OrderUseCase\CalculateTaxesUseCase;
use PHPUnit\Framework\TestCase;

class CalculateTaxesUseCaseTest extends TestCase
{
    private $emProvider;
    private $taxService;
    private $useCase;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->taxService = $this->createMock(TaxCalculationService::class);

        $this->useCase = new CalculateTaxesUseCase(
            $this->emProvider,
            $this->taxService
        );
    }

    public function testExecuteDelegatesToService(): void
    {
        $order = $this->createMock(Order::class);
        $subtotal = 100.0;
        $expectedTax = 20.0;

        $this->taxService->expects($this->once())
            ->method('calculateTaxes')
            ->with($order, $subtotal)
            ->willReturn($expectedTax);

        $result = $this->useCase->execute($order, $subtotal);

        $this->assertEquals($expectedTax, $result);
    }
}
