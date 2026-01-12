<?php

namespace App\Tests\Unit\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\EntityRetrieverService;
use App\Services\OrderService\OrderItemService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\OrderUseCase\ProcessOrderItemsUseCase;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ProcessOrderItemsUseCaseTest extends TestCase
{
    private $emProvider;
    private $entityRetriever;
    private $orderItemService;
    private $logger;
    private $entityManager;
    private $useCase;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->entityRetriever = $this->createMock(EntityRetrieverService::class);
        $this->orderItemService = $this->createMock(OrderItemService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->emProvider->method('getEntityManager')->willReturn($this->entityManager);

        $this->useCase = new ProcessOrderItemsUseCase(
            $this->emProvider,
            $this->entityRetriever,
            $this->orderItemService,
            $this->logger
        );
    }

    public function testExecuteSuccessStandardOrder(): void
    {
        // 1. Setup Data
        $order = $this->createMock(Order::class);
        $updateStockUseCase = $this->createMock(UpdateStockAndInventoryUseCase::class);
        $items = [
            ['productVariantId' => 10, 'quantity' => 2]
        ];
        $typeOrderId = 1; // Order
        $priceShipping = 15.0;

        // 2. Mock Entities
        $productVariant = $this->createMock(ProductVariant::class);
        $product = $this->createMock(Product::class);

        $productVariant->method('getProduct')->willReturn($product);
        $productVariant->method('getStockQuantity')->willReturn(10); // Sufficient stock
        $product->method('getName')->willReturn('Test Product');
        $product->method('isBookable')->willReturn(false);

        $this->entityRetriever->expects($this->once())
            ->method('findOrFail')
            ->with(ProductVariant::class, 10, 'Product variant not found')
            ->willReturn($productVariant);

        // 3. Mock Stock Update
        $updateStockUseCase->expects($this->once())
            ->method('execute')
            ->with($productVariant, 2, false); // false for isCancel

        // 4. Mock Order Item Creation
        $orderItem = $this->createMock(OrderItems::class);
        $orderItem->method('getTotalPrice')->willReturn(50.0);

        $this->orderItemService->expects($this->once())
            ->method('createOrderItem')
            ->with($order, $productVariant, 2)
            ->willReturn($orderItem);

        // 5. Mock Persistence
        $this->entityManager->expects($this->exactly(2))->method('persist'); // Order and OrderItem

        // 6. Execute
        $subtotal = $this->useCase->execute($order, $items, $updateStockUseCase, $typeOrderId, $priceShipping);

        // 7. Verify Subtotal (Shipping 15.0 + Item 50.0 = 65.0)
        $this->assertEquals(65.0, $subtotal);
    }

    public function testExecuteInsufficientStock(): void
    {
        $order = $this->createMock(Order::class);
        $updateStockUseCase = $this->createMock(UpdateStockAndInventoryUseCase::class);
        $items = [
            ['productVariantId' => 10, 'quantity' => 20]
        ];
        $typeOrderId = 1;

        $productVariant = $this->createMock(ProductVariant::class);
        $product = $this->createMock(Product::class);

        $productVariant->method('getProduct')->willReturn($product);
        $productVariant->method('getStockQuantity')->willReturn(5); // Insufficient
        $product->method('getName')->willReturn('Low Stock Product');
        $product->method('isBookable')->willReturn(false);

        $this->entityRetriever->method('findOrFail')->willReturn($productVariant);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Stock insuffisant pour le produit "Low Stock Product" (Stock: 5, Demandé: 20)');

        $this->useCase->execute($order, $items, $updateStockUseCase, $typeOrderId, null);
    }

    public function testExecuteSuccessCancelOrder(): void
    {
        // 1. Setup Data
        $order = $this->createMock(Order::class);
        $updateStockUseCase = $this->createMock(UpdateStockAndInventoryUseCase::class);
        $items = [
            ['productVariantId' => 10, 'quantity' => 5]
        ];
        $typeOrderId = 2; // Cancel/Refund
        $priceShipping = 0.0;

        // 2. Mock Entities
        $productVariant = $this->createMock(ProductVariant::class);
        $product = $this->createMock(Product::class);

        $productVariant->method('getProduct')->willReturn($product);
        // Stock check should be skipped or irrelevant for cancel, 
        // but we verify updateStock logic uses isCancel=true
        $product->method('isBookable')->willReturn(false);

        $this->entityRetriever->method('findOrFail')->willReturn($productVariant);

        // 3. Mock Stock Update
        $updateStockUseCase->expects($this->once())
            ->method('execute')
            ->with($productVariant, 5, true); // true for isCancel

        // 4. Mock Order Item Creation
        $orderItem = $this->createMock(OrderItems::class);
        $orderItem->method('getTotalPrice')->willReturn(100.0);

        $this->orderItemService->method('createOrderItem')->willReturn($orderItem);

        // 5. Execute
        $subtotal = $this->useCase->execute($order, $items, $updateStockUseCase, $typeOrderId, $priceShipping);

        $this->assertEquals(100.0, $subtotal);
    }
}
