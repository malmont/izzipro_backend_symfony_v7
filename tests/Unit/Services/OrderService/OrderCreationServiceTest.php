<?php

namespace App\Tests\Unit\Services\OrderService;

use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\Order;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\StatusCommande;
use App\Entity\User;
use App\Services\OrderService\OrderCreationService;
use PHPUnit\Framework\TestCase;

class OrderCreationServiceTest extends TestCase
{
    public function testCreateOrder(): void
    {
        // Arrange
        $user = $this->createMock(User::class);
        $orderSource = $this->createMock(OrderSource::class);
        $address = $this->createMock(Adress::class);
        $carrier = $this->createMock(Carrier::class);
        $statusCommande = $this->createMock(StatusCommande::class);
        $orderType = $this->createMock(OrderType::class);

        $service = new OrderCreationService();

        // Act
        $order = $service->createOrder(
            $user,
            $orderSource,
            $address,
            $carrier,
            $statusCommande,
            $orderType
        );

        // Assert
        $this->assertInstanceOf(Order::class, $order);
        $this->assertStringStartsWith('REF#', $order->getReference());
        $this->assertSame($user, $order->getUserId());
        $this->assertSame($orderType, $order->getOrderType());
        $this->assertSame($orderSource, $order->getOrderSource());
        $this->assertInstanceOf(\DateTimeInterface::class, $order->getOrderDate());
        $this->assertInstanceOf(\DateTime::class, $order->getStatusUpdatedAt());
        $this->assertSame($address, $order->getShippingAdress());
        $this->assertSame($carrier, $order->getCarrier());
        $this->assertSame($statusCommande, $order->getStatus());
    }
}
