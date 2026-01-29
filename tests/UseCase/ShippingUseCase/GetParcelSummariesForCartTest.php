<?php

namespace App\Tests\UseCase\ShippingUseCase;

use App\Dto\CartItemDto;
use App\Dto\ParcelSummaryDto;
use App\Entity\ProductShipping;
use App\Services\ShippingService\ShippingService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\ShippingUseCase\GetParcelSummariesForCart;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use LogicException;

class GetParcelSummariesForCartTest extends TestCase
{
    public function testExecuteReturnsParcelSummaries()
    {
        // Arrange
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $shippingService = $this->createMock(ShippingService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(EntityRepository::class);

        $emProvider->method('getEntityManager')->willReturn($em);
        $em->method('getRepository')->with(ProductShipping::class)->willReturn($repo);

        $productId = 123;
        $quantity = 2;
        $cartItem = new CartItemDto($productId, $quantity);
        $cartItems = [$cartItem];

        $productShipping = new ProductShipping();
        // Assuming ProductShipping entity can be instantiated directly. 
        // If it interacts with DB we might need to mock it too but for now simple instance is enough as it's passed around.

        $repo->method('findOneBy')->with(['product' => $productId])->willReturn($productShipping);

        $rawSummaries = [
            [
                'index' => 1,
                'weight' => 10,
                'length' => 20,
                'width' => 30,
                'height' => 40
            ],
            [
                'index' => 2,
                'weight' => 10,
                'length' => 20,
                'width' => 30,
                'height' => 40
            ]
        ];

        // The service receives array of ProductShipping.
        // Since quantity is 2, it receives 2 ProductShipping objects.
        $shippingService->expects($this->once())
            ->method('getParcelSummaries')
            ->with([$productShipping, $productShipping])
            ->willReturn($rawSummaries);

        $useCase = new GetParcelSummariesForCart($emProvider, $shippingService);

        // Act
        $result = $useCase->execute($cartItems);

        // Assert
        $this->assertCount(2, $result);
        $this->assertInstanceOf(ParcelSummaryDto::class, $result[0]);
        $this->assertEquals(1, $result[0]->index);
        $this->assertEquals(10, $result[0]->weight);
        $this->assertEquals(20, $result[0]->length);
        $this->assertEquals(30, $result[0]->width);
        $this->assertEquals(40, $result[0]->height);
        $this->assertEquals(2, $result[1]->index);
    }

    public function testExecuteThrowsExceptionWhenProductShippingNotFound()
    {
        // Arrange
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $shippingService = $this->createMock(ShippingService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(EntityRepository::class);

        $emProvider->method('getEntityManager')->willReturn($em);
        $em->method('getRepository')->with(ProductShipping::class)->willReturn($repo);

        $productId = 123;
        $cartItem = new CartItemDto($productId, 1);
        $cartItems = [$cartItem];

        $repo->method('findOneBy')->with(['product' => $productId])->willReturn(null);

        $useCase = new GetParcelSummariesForCart($emProvider, $shippingService);

        // Assert
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Aucune configuration d'expédition trouvée pour le produit ID 123");

        // Act
        $useCase->execute($cartItems);
    }
}
