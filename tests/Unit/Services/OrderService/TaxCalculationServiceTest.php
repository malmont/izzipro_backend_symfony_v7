<?php

namespace App\Tests\Unit\Services\OrderService;

use App\Entity\Order;
use App\Entity\OrderTax;
use App\Entity\Tax;
use App\Services\OrderService\TaxCalculationService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class TaxCalculationServiceTest extends TestCase
{
    public function testCalculateTaxesCalculatesCorrectlyAndPersists(): void
    {
        // 1. Setup Mocks
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $taxRepository = $this->createMock(EntityRepository::class);
        $order = $this->createMock(Order::class);

        // 2. Setup Data
        $subtotal = 100.0;

        $tax1 = $this->createMock(Tax::class);
        $tax1->method('getRate')->willReturn(0.10); // 10%

        $tax2 = $this->createMock(Tax::class);
        $tax2->method('getRate')->willReturn(0.05); // 5%

        $taxes = [$tax1, $tax2];

        // 3. Expectations

        // Provider -> EM
        $emProvider->expects($this->once())
            ->method('getEntityManager')
            ->willReturn($em);

        // EM -> Repo
        $em->expects($this->once())
            ->method('getRepository')
            ->with(Tax::class)
            ->willReturn($taxRepository);

        // Repo -> findAll
        $taxRepository->expects($this->once())
            ->method('findAll')
            ->willReturn($taxes);

        // Order -> addOrderTax (Should be called twice, once for each tax)
        $order->expects($this->exactly(2))
            ->method('addOrderTax')
            ->with($this->isInstanceOf(OrderTax::class));

        // EM -> persist (Should be called twice)
        $em->expects($this->exactly(2))
            ->method('persist')
            ->with($this->isInstanceOf(OrderTax::class));

        // 4. Execution
        $service = new TaxCalculationService($emProvider);
        $totalTax = $service->calculateTaxes($order, $subtotal);

        // 5. Verification
        // Tax 1: 100 * 0.10 = 10
        // Tax 2: 100 * 0.05 = 5
        // Total: 15
        $this->assertEquals(15.0, $totalTax);
    }

    public function testCalculateTaxesWithNoTaxes(): void
    {
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $taxRepository = $this->createMock(EntityRepository::class);
        $order = $this->createMock(Order::class);

        $emProvider->method('getEntityManager')->willReturn($em);
        $em->method('getRepository')->willReturn($taxRepository);
        $taxRepository->method('findAll')->willReturn([]); // No taxes

        $order->expects($this->never())->method('addOrderTax');
        $em->expects($this->never())->method('persist');

        $service = new TaxCalculationService($emProvider);
        $totalTax = $service->calculateTaxes($order, 100.0);

        $this->assertEquals(0.0, $totalTax);
    }
}
