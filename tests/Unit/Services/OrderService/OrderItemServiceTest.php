<?php

namespace App\Tests\Unit\Services\OrderService;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\OrderService\OrderItemService;
use PHPUnit\Framework\TestCase;

class OrderItemServiceTest extends TestCase
{
    public function testCreateOrderItem(): void
    {
        // Arrange
        $order = $this->createMock(Order::class);
        $productVariant = $this->createMock(ProductVariant::class);
        $product = $this->createMock(Product::class);
        $price = 100.0;
        $quantity = 2;

        $product->method('getPrice')->willReturn($price);
        $productVariant->method('getProduct')->willReturn($product);

        // Expect existing of addOrderItem call on Order
        $order->expects($this->once())
            ->method('addOrderItem')
            ->with($this->isInstanceOf(OrderItems::class));

        $service = new OrderItemService();

        // Act
        $orderItem = $service->createOrderItem($order, $productVariant, $quantity);

        // Assert
        $this->assertInstanceOf(OrderItems::class, $orderItem);
        $this->assertSame($productVariant, $orderItem->getProductVariant());
        $this->assertEquals($quantity, $orderItem->getQuantity());
        $this->assertEquals($price, $orderItem->getUnitPrice());
        $this->assertEquals($price * $quantity, $orderItem->getTotalPrice());
    }
}
