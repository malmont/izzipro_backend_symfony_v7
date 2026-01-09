<?php

namespace App\Tests\Unit\Services\OrderService;

use App\Entity\InventoryMovements;
use App\Entity\MovementType;
use App\Entity\ProductVariant;
use App\Services\OrderService\InventoryMovementService;
use App\Services\ProductVariantService\ProductVariantExistenceService;
use PHPUnit\Framework\TestCase;

class InventoryMovementServiceTest extends TestCase
{
    private $existenceService;
    private $service;

    protected function setUp(): void
    {
        $this->existenceService = $this->createMock(ProductVariantExistenceService::class);
        $this->service = new InventoryMovementService($this->existenceService);
    }

    public function testCreateInventoryMovementReturnsEntity(): void
    {
        $variant = $this->createMock(ProductVariant::class);
        $movementType = new MovementType(); // Use Entity
        $movementType->setName('Correction');

        // Mock existence check to return false (allow creation)
        $this->existenceService->expects($this->once())
            ->method('doesVariantExist')
            ->with($variant)
            ->willReturn(false);

        $result = $this->service->createInventoryMovement(
            $variant,
            10,
            15,
            5,
            $movementType
        );

        $this->assertInstanceOf(InventoryMovements::class, $result);
        $this->assertEquals(10, $result->getStockBeforeMovement());
        $this->assertEquals(15, $result->getStockAfterMovement());
        $this->assertEquals(5, $result->getQuantity());
        $this->assertSame($movementType, $result->getMovementType());
        $this->assertNotNull($result->getMovementDate());
    }

    public function testCreateInventoryMovementThrowsExceptionWhenStockExists(): void
    {
        $variant = $this->createMock(ProductVariant::class);
        $movementType = new MovementType();

        // Mock existence check to return true (prevent creation)
        $this->existenceService->expects($this->once())
            ->method('doesVariantExist')
            ->with($variant)
            ->willReturn(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Stock existant, vous pouvez modifier le stock de l'existant");

        $this->service->createInventoryMovement(
            $variant,
            10,
            15,
            5,
            $movementType,
            false // isCancellation
        );
    }
}
