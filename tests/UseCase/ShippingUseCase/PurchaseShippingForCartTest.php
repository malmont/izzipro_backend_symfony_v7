<?php

namespace App\Tests\UseCase\ShippingUseCase;

use App\Dto\CartItemDto;
use App\Dto\ShipmentLabelDto;
use App\Entity\Order;
use App\Entity\Parcel;
use App\Entity\ProductShipping;
use App\Entity\ShippingLabel;
use App\Entity\ShippingOrder;
use App\Services\ShippingService\ShipmentAddressBuilder;
use App\Services\ShippingService\ShippingService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\ShippingUseCase\PurchaseShippingForCart;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use LogicException;

class PurchaseShippingForCartTest extends TestCase
{
    public function testExecutePurchasesShippingAndPersistsEntities()
    {
        // Arrange
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $shippingService = $this->createMock(ShippingService::class);
        $addressBuilder = $this->createMock(ShipmentAddressBuilder::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(EntityRepository::class);

        $emProvider->method('getEntityManager')->willReturn($em);

        // Mock Repository behavior for multiple calls
        $em->method('getRepository')->with(ProductShipping::class)->willReturn($repo);

        $orderId = 123;
        $order = $this->createMock(Order::class);

        // Mock finding Order
        $em->expects($this->exactly(1)) // Once for Order
            ->method('find')
            ->with(Order::class, $orderId)
            ->willReturn($order);

        $productId = 456;
        $quantity = 1;
        $cartItem = new CartItemDto($productId, $quantity);
        $cartItems = [$cartItem];
        $toAddress = ['zip' => '12345'];
        $fromAddress = [];
        $carrierAccountId = 'ca_123';
        $service = 'Express';

        $productShipping = new ProductShipping();
        // Mock finding ProductShipping
        $repo->method('findOneBy')->with(['product' => $productId])->willReturn($productShipping);

        $formattedTo = ['zip' => '12345', 'formatted' => true];
        $formattedFrom = ['formatted' => true];
        $addressBuilder->method('buildTo')->with($toAddress)->willReturn($formattedTo);
        $addressBuilder->method('buildFrom')->willReturn($formattedFrom);

        $parcelsData = [
            [
                'weight' => 10,
                'length' => 20,
                'width' => 30,
                'height' => 40
            ]
        ];
        $shippingService->method('getParcelSummaries')->willReturn($parcelsData);

        $labelsRaw = [
            [
                'label_url' => 'http://example.com/label.png',
                'tracking_code' => 'TRACK123'
            ]
        ];
        $shippingService->expects($this->once())
            ->method('purchase')
            // .->with(...) // can add strict checks here
            ->willReturn($labelsRaw);

        // Expect persists
        // 1 ShippingOrder
        // 1 Parcel
        // 1 ShippingLabel
        $em->expects($this->exactly(3))->method('persist');
        $em->expects($this->once())->method('flush');

        $useCase = new PurchaseShippingForCart($emProvider, $shippingService, $addressBuilder);

        // Act
        $result = $useCase->execute($cartItems, $toAddress, $fromAddress, $carrierAccountId, $service, $orderId);

        // Assert
        $this->assertCount(1, $result);
        $this->assertInstanceOf(ShipmentLabelDto::class, $result[0]);
        $this->assertEquals('http://example.com/label.png', $result[0]->labelUrl);
        $this->assertEquals('TRACK123', $result[0]->trackingCode);
    }

    public function testExecuteThrowsExceptionWhenOrderNotFound()
    {
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $shippingService = $this->createMock(ShippingService::class);
        $addressBuilder = $this->createMock(ShipmentAddressBuilder::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $emProvider->method('getEntityManager')->willReturn($em);
        $em->method('find')->willReturn(null);

        $useCase = new PurchaseShippingForCart($emProvider, $shippingService, $addressBuilder);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Order #123 introuvable.");

        $useCase->execute([], [], [], 'ca', 'srv', 123);
    }
}
