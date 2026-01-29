<?php

namespace App\Tests\UseCase\ShippingUseCase;

use App\Dto\CartItemDto;
use App\Entity\ProductShipping;
use App\Services\ShippingService\ShipmentAddressBuilder;
use App\Services\ShippingService\ShippingService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\ShippingUseCase\GetShippingRatesForCart;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use LogicException;

class GetShippingRatesForCartTest extends TestCase
{
    public function testExecuteReturnsShippingRates()
    {
        // Arrange
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $shippingService = $this->createMock(ShippingService::class);
        $addressBuilder = $this->createMock(ShipmentAddressBuilder::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(EntityRepository::class);

        $emProvider->method('getEntityManager')->willReturn($em);
        $em->method('getRepository')->with(ProductShipping::class)->willReturn($repo);

        $productId = 123;
        $quantity = 2;
        $cartItem = new CartItemDto($productId, $quantity);
        $cartItems = [$cartItem];
        $toAddress = ['zip' => '12345'];
        $fromAddress = []; // ShipmentAddressBuilder uses DB, but here we mock it
        $carrierAccountIds = ['ca_123'];

        $productShipping = new ProductShipping();

        $repo->method('findOneBy')->with(['product' => $productId])->willReturn($productShipping);

        $formattedTo = ['zip' => '12345', 'formatted' => true];
        $formattedFrom = ['zip' => '54321', 'formatted' => true];

        $addressBuilder->method('buildTo')->with($toAddress)->willReturn($formattedTo);
        $addressBuilder->method('buildFrom')->willReturn($formattedFrom);

        $expectedRates = [['id' => 'rate_1', 'amount' => 1000]];

        $shippingService->expects($this->once())
            ->method('getRates')
            ->with(
                [$productShipping, $productShipping],
                $formattedTo,
                $formattedFrom,
                $carrierAccountIds
            )
            ->willReturn($expectedRates);

        $useCase = new GetShippingRatesForCart($emProvider, $shippingService, $addressBuilder);

        // Act
        $result = $useCase->execute($cartItems, $toAddress, $fromAddress, $carrierAccountIds);

        // Assert
        $this->assertEquals($expectedRates, $result);
    }

    public function testExecuteThrowsExceptionWhenProductShippingNotFound()
    {
        // Arrange
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $shippingService = $this->createMock(ShippingService::class);
        $addressBuilder = $this->createMock(ShipmentAddressBuilder::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(EntityRepository::class);

        $emProvider->method('getEntityManager')->willReturn($em);
        $em->method('getRepository')->with(ProductShipping::class)->willReturn($repo);

        $productId = 123;
        $cartItem = new CartItemDto($productId, 1);
        $cartItems = [$cartItem];
        $toAddress = [];
        $fromAddress = [];
        $carrierAccountIds = [];

        $repo->method('findOneBy')->with(['product' => $productId])->willReturn(null);

        $useCase = new GetShippingRatesForCart($emProvider, $shippingService, $addressBuilder);

        // Assert
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Aucune configuration d'expédition trouvée pour le produit ID 123");

        // Act
        $useCase->execute($cartItems, $toAddress, $fromAddress, $carrierAccountIds);
    }
}
