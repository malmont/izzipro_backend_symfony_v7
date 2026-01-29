<?php

namespace App\Tests\UseCase\ShippingUseCase;

use App\Dto\CartItemDto;
use App\Dto\RateOptionDto;
use App\Dto\RateSummaryDto;
use App\Entity\ProductShipping;
use App\Services\ShippingService\ShipmentAddressBuilder;
use App\Services\ShippingService\ShippingService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\ShippingUseCase\GetShippingSummaryForCart;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use LogicException;

class GetShippingSummaryForCartTest extends TestCase
{
    public function testExecuteReturnsAggregatedRateSummaries()
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
        $quantity = 2; // Will generate 2 parcels
        $cartItem = new CartItemDto($productId, $quantity);
        $cartItems = [$cartItem];
        $toAddress = ['zip' => '12345'];
        $fromAddress = [];
        $carrierAccountIds = ['ca_123'];

        $productShipping = new ProductShipping();

        $repo->method('findOneBy')->with(['product' => $productId])->willReturn($productShipping);

        $formattedTo = ['zip' => '12345', 'formatted' => true];
        $formattedFrom = ['formatted' => true];

        $addressBuilder->method('buildTo')->with($toAddress)->willReturn($formattedTo);
        $addressBuilder->method('buildFrom')->willReturn($formattedFrom);

        // We expect ShippingService::getRates to return options for EACH parcel.
        // Since we have 2 parcels (quantity 2), and let's say 2 carriers.
        // Actually getRates returns a flat list of ALL rates for ALL parcels or just a list of possible rates?
        // ShippingService::getRates implementation iterates over parcels and adds rates to $allRates.
        // So if we have 2 parcels and 1 carrier offering 1 service, we get 2 RateOptionDto objects (one for each parcel).

        // Let's simulate:
        // Parcel 1: Carrier A, Service S1, Price 10, Days 3
        // Parcel 2: Carrier A, Service S1, Price 10, Days 3
        // Parcel 1: Carrier B, Service S2, Price 15, Days 5
        // Parcel 2: Carrier B, Service S2, Price 15, Days 5

        $rate1_p1 = new RateOptionDto('CarrierA', 'ServiceS1', 10.0, 'USD', 3);
        $rate1_p2 = new RateOptionDto('CarrierA', 'ServiceS1', 10.0, 'USD', 3);
        $rate2_p1 = new RateOptionDto('CarrierB', 'ServiceS2', 15.0, 'USD', 4);
        $rate2_p2 = new RateOptionDto('CarrierB', 'ServiceS2', 15.0, 'USD', 5); // Max days will be 5

        $allRates = [$rate1_p1, $rate1_p2, $rate2_p1, $rate2_p2];

        $shippingService->expects($this->once())
            ->method('getRates')
            ->with(
                [$productShipping, $productShipping],
                $formattedTo,
                $formattedFrom,
                $carrierAccountIds
            )
            ->willReturn($allRates);

        $useCase = new GetShippingSummaryForCart($emProvider, $shippingService, $addressBuilder);

        // Act
        $result = $useCase->execute($cartItems, $toAddress, $fromAddress, $carrierAccountIds);

        // Assert
        $this->assertCount(2, $result);

        // Check Aggregation for CarrierA
        // It's undetermined order because of hash map, so let's find it.
        $summaryA = null;
        $summaryB = null;
        foreach ($result as $r) {
            if ($r->carrier === 'CarrierA') $summaryA = $r;
            if ($r->carrier === 'CarrierB') $summaryB = $r;
        }

        $this->assertNotNull($summaryA);
        $this->assertEquals('ServiceS1', $summaryA->service);
        $this->assertEquals(20.0, $summaryA->totalPrice); // 10 + 10
        $this->assertEquals(3, $summaryA->estimatedDays);
        $this->assertEquals(2, $summaryA->parcelCount);

        $this->assertNotNull($summaryB);
        $this->assertEquals('ServiceS2', $summaryB->service);
        $this->assertEquals(30.0, $summaryB->totalPrice); // 15 + 15
        $this->assertEquals(5, $summaryB->estimatedDays); // Max of 4 and 5
        $this->assertEquals(2, $summaryB->parcelCount);
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

        $productId = 999;
        $cartItem = new CartItemDto($productId, 1);
        $cartItems = [$cartItem];

        $repo->method('findOneBy')->with(['product' => $productId])->willReturn(null);

        $useCase = new GetShippingSummaryForCart($emProvider, $shippingService, $addressBuilder);

        // Assert
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Pas de config shipping pour le produit ID 999");

        // Act
        $useCase->execute($cartItems, [], [], []);
    }
}
