<?php

namespace App\Tests\Unit\UseCase\OrderUseCase;

use App\Dto\AdressOutputDTO;
use App\Dto\OrderDTO;
use App\Entity\Adress;
use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderSource;
use App\Entity\StatusCommande;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\Services\OrderService\OrderService;
use App\UseCase\OrderUseCase\GetOrdersBySourceUseCase;
use PHPUnit\Framework\TestCase;

class GetOrdersBySourceUseCaseTest extends TestCase
{
    private $orderService;
    private $useCase;

    protected function setUp(): void
    {
        $this->orderService = $this->createMock(OrderService::class);
        $this->useCase = new GetOrdersBySourceUseCase($this->orderService);
    }

    public function testExecuteReturnsOrderDTOs()
    {
        // 1. Setup Mock Data
        $orderSourceId = 1;
        $host = 'http://example.com';
        $days = 30;

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(101);
        $order->method('getReference')->willReturn('REF-123');
        $order->method('getTotalAmount')->willReturn(150.0);
        $order->method('getSubTotal')->willReturn(120.0);
        $order->method('getTotalTax')->willReturn(20.0);
        $order->method('getShippingCost')->willReturn(10.0);

        $date = new \DateTimeImmutable('2023-01-01 10:00:00');
        $order->method('getOrderDate')->willReturn($date);

        // User
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(55);
        $order->method('getUserId')->willReturn($user);

        // Address
        $address = $this->createMock(Adress::class);
        $address->method('getId')->willReturn(10);
        // Address methods needed by AdressOutputDTO constructor
        $address->method('getFirstname')->willReturn('John');
        $address->method('getLastname')->willReturn('Doe');
        $address->method('getFullname')->willReturn('John Doe');
        $address->method('getAddress')->willReturn('123 Main St');
        $address->method('getCity')->willReturn('Paris');
        $address->method('getCodepostal')->willReturn('75000');
        $address->method('getCountry')->willReturn('France');
        $address->method('getPhone')->willReturn('1234567890');
        // Optional nullable fields
        $address->method('getCompany')->willReturn(null);
        $address->method('getComplement')->willReturn(null);
        $address->method('getProvince')->willReturn(null);
        $address->method('getUserAdress')->willReturn(null);

        $order->method('getShippingAdress')->willReturn($address);

        // OrderSource
        $source = $this->createMock(OrderSource::class);
        $source->method('getName')->willReturn('Web');
        $order->method('getOrderSource')->willReturn($source);

        // Status
        $status = $this->createMock(StatusCommande::class);
        $status->method('getName')->willReturn('Paid');
        $order->method('getStatus')->willReturn($status);

        // Items
        $item = $this->createMock(OrderItems::class);
        $item->method('getId')->willReturn(900);
        $item->method('getQuantity')->willReturn(2);
        $item->method('getUnitPrice')->willReturn(50.0);
        $item->method('getTotalPrice')->willReturn(100.0);

        // Variant & Product mocks for ItemDTO
        $variant = $this->createMock(ProductVariant::class);
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(77);
        $product->method('getName')->willReturn('T-Shirt');
        $product->method('getImage')->willReturn('tshirt.jpg');

        $variant->method('getProduct')->willReturn($product);
        $variant->method('getColor')->willReturn(null);
        $variant->method('getSize')->willReturn(null);
        $variant->method('getOptionValues')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([]));

        $item->method('getProductVariant')->willReturn($variant);
        $item->method('getBooking')->willReturn(null);

        $order->method('getOrderItems')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([$item]));

        // 2. Setup Service Expectation
        $this->orderService->expects($this->once())
            ->method('getOrdersByOrderSource')
            ->with($orderSourceId, $days)
            ->willReturn([$order]);

        // 3. Execute
        $result = $this->useCase->execute($orderSourceId, $host, $days);

        // 4. Assertions
        $this->assertCount(1, $result);
        $this->assertInstanceOf(OrderDTO::class, $result[0]);
        $dto = $result[0];

        $this->assertEquals(101, $dto->id);
        $this->assertEquals('REF-123', $dto->reference);
        $this->assertEquals(150.0, $dto->totalAmount);
        $this->assertEquals('2023-01-01 10:00:00', $dto->orderDate);
        $this->assertEquals(55, $dto->userId);

        $this->assertInstanceOf(AdressOutputDTO::class, $dto->shippingAdress);
        $this->assertEquals('John', $dto->shippingAdress->firstname);

        $this->assertEquals('Web', $dto->orderSource);
        $this->assertEquals('Paid', $dto->status);

        $this->assertCount(1, $dto->orderItems);
        $this->assertEquals(900, $dto->orderItems[0]->id);
        // host handling in image: 'tshirt.jpg' -> http://example.com/assets/uploads/products/tshirt.jpg
        $this->assertEquals('http://example.com/assets/uploads/products/tshirt.jpg', $dto->orderItems[0]->productImage);
    }

    public function testExecuteWithNullsAndEmptyResult()
    {
        $this->orderService->method('getOrdersByOrderSource')
            ->willReturn([]);

        $result = $this->useCase->execute(1, 'host');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
