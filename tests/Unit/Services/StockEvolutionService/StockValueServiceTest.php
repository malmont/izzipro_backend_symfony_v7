<?php

namespace App\Tests\Unit\Services\StockEvolutionService;

use App\Entity\InventoryMovements;
use App\Entity\Product;
use App\Repository\InventoryMovementsRepository;
use App\Repository\ProductRepository;
use App\Services\StockEvolutionService\StockValueService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StockValueServiceTest extends TestCase
{
    private TenantEntityManagerProvider|MockObject $emProvider;
    private EntityManagerInterface|MockObject $entityManager;
    private ProductRepository|MockObject $productRepository;
    private InventoryMovementsRepository|MockObject $inventoryMovementsRepository;
    private StockValueService $service;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->productRepository = $this->createMock(ProductRepository::class);
        $this->inventoryMovementsRepository = $this->createMock(InventoryMovementsRepository::class);

        $this->emProvider->method('getEntityManager')->willReturn($this->entityManager);

        $this->entityManager->method('getRepository')
            ->willReturnMap([
                [Product::class, $this->productRepository],
                [InventoryMovements::class, $this->inventoryMovementsRepository],
            ]);

        $this->service = new StockValueService($this->emProvider);
    }

    private function createMockProduct(float $purchasePrice, float $coeff): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getPurchasePrice')->willReturn($purchasePrice);
        $product->method('getCoefficientMultiplier')->willReturn($coeff);
        return $product;
    }

    public function testGetStockValueForCurrentMonth(): void
    {
        $product1 = $this->createMockProduct(10.0, 2.0); // Price: 20
        $product2 = $this->createMockProduct(5.0, 3.0);  // Price: 15

        $this->productRepository->method('findAll')->willReturn([$product1, $product2]);

        $this->inventoryMovementsRepository->method('getStockQuantityAtDate')
            ->willReturnCallback(function (Product $product, \DateTime $date) use ($product1, $product2) {
                if ($product === $product1) {
                    return 5;
                }
                if ($product === $product2) {
                    return 2;
                }
                return 0;
            });

        $result = $this->service->getStockValueForCurrentMonth();

        $this->assertCount(1, $result);
        $this->assertEquals(130.0, $result[0]['stock_value']);
        $this->assertEquals((new \DateTime('last day of this month'))->format('F Y'), $result[0]['month']);
    }

    public function testGetStockValueForLastMonth(): void
    {
        $product1 = $this->createMockProduct(10.0, 2.0);
        $this->productRepository->method('findAll')->willReturn([$product1]);

        $this->inventoryMovementsRepository->method('getStockQuantityAtDate')
            ->willReturn(10); // 20 * 10 = 200

        $result = $this->service->getStockValueForLastMonth();

        $this->assertCount(1, $result);
        $this->assertEquals(200.0, $result[0]['stock_value']);
        $this->assertEquals((new \DateTime('last day of last month'))->format('F Y'), $result[0]['month']);
    }

    public function testGetStockValueForTwoMonthsAgo(): void
    {
        $product1 = $this->createMockProduct(100.0, 1.0);
        $this->productRepository->method('findAll')->willReturn([$product1]);

        $this->inventoryMovementsRepository->method('getStockQuantityAtDate')
            ->willReturn(1); // 100 * 1 = 100

        $result = $this->service->getStockValueForTwoMonthsAgo();

        $this->assertCount(1, $result);
        $this->assertEquals(100.0, $result[0]['stock_value']);
        $this->assertEquals((new \DateTime('last day of -2 month'))->format('F Y'), $result[0]['month']);
    }

    public function testGetStockValueCurrentMonth(): void
    {
        $this->productRepository->expects($this->once())
            ->method('calculateCurrentStockValue')
            ->willReturn(5000.0);

        $result = $this->service->getStockValueCurrentMonth();

        $this->assertArrayHasKey('stock_value_current_month', $result);
        $this->assertEquals(5000.0, $result['stock_value_current_month'][0]['stock_value']);
        $this->assertEquals((new \DateTime('last day of this month'))->format('F Y'), $result['stock_value_current_month'][0]['month']);
    }
}
