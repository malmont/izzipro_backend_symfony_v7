<?php

namespace App\Tests\Unit\UseCase\OrderUseCase;

use App\Entity\InventoryMovements;
use App\Entity\MovementType;
use App\Entity\ProductVariant;
use App\Services\OrderService\InventoryMovementService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class UpdateStockAndInventoryUseCaseTest extends TestCase
{
    private $emProvider;
    private $inventoryMovementService;
    private $entityManager;
    private $useCase;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->inventoryMovementService = $this->createMock(InventoryMovementService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->emProvider->method('getEntityManager')->willReturn($this->entityManager);

        $this->useCase = new UpdateStockAndInventoryUseCase(
            $this->emProvider,
            $this->inventoryMovementService
        );
    }

    public function testExecuteNormalOrderStockDecrease(): void
    {
        // 1. Setup Data
        $productVariant = new ProductVariant();
        $productVariant->setStockQuantity(100);
        $quantity = 5;
        $isCancellation = false;

        // 2. Mock Repositories
        $movementType = new MovementType();
        $movementTypeRepo = $this->createMock(EntityRepository::class);
        $movementTypeRepo->method('find')->with(2)->willReturn($movementType); // 2 = Order (Decrease)

        $this->entityManager->method('getRepository')
            ->with(MovementType::class)
            ->willReturn($movementTypeRepo);

        // 3. Mock Inventory Service
        $inventoryMovement = new InventoryMovements();
        $this->inventoryMovementService->expects($this->once())
            ->method('createInventoryMovement')
            ->with(
                $productVariant,
                100, // Stock before
                95,  // Stock after
                5,   // Quantity
                $movementType,
                $isCancellation
            )
            ->willReturn($inventoryMovement);

        // 4. Mock Persistence
        $this->entityManager->expects($this->exactly(2))->method('persist'); // Variant and Movement

        // 5. Execute
        $this->useCase->execute($productVariant, $quantity, $isCancellation);

        // 6. Verify Stock
        $this->assertEquals(95, $productVariant->getStockQuantity());
    }

    public function testExecuteCancellationStockIncrease(): void
    {
        // 1. Setup Data
        $productVariant = new ProductVariant();
        $productVariant->setStockQuantity(95);
        $quantity = 5;
        $isCancellation = true;

        // 2. Mock Repositories
        $movementType = new MovementType();
        $movementTypeRepo = $this->createMock(EntityRepository::class);
        $movementTypeRepo->method('find')->with(1)->willReturn($movementType); // 1 = Cancellation (Increase)

        $this->entityManager->method('getRepository')
            ->with(MovementType::class)
            ->willReturn($movementTypeRepo);

        // 3. Mock Inventory Service
        $inventoryMovement = new InventoryMovements();
        $this->inventoryMovementService->expects($this->once())
            ->method('createInventoryMovement')
            ->with(
                $productVariant,
                95,  // Stock before
                100, // Stock after
                5,   // Quantity
                $movementType,
                $isCancellation
            )
            ->willReturn($inventoryMovement);

        // 4. Persistence
        $this->entityManager->expects($this->exactly(2))->method('persist');

        // 5. Execute
        $this->useCase->execute($productVariant, $quantity, $isCancellation);

        // 6. Verify Stock
        $this->assertEquals(100, $productVariant->getStockQuantity());
    }

    public function testExecuteNewProductVariantAdjustmentPositive(): void
    {
        // 1. Setup Data
        // Case: Stock increased manually (from 10 to 15)
        $productVariant = new ProductVariant();
        $productVariant->setStockQuantity(15);
        $stockBeforeMovement = 10;
        $movementTypeId = 3; // Adjustment

        // 2. Repos
        $movementType = new MovementType();
        $movementTypeRepo = $this->createMock(EntityRepository::class);
        $movementTypeRepo->method('find')->with($movementTypeId)->willReturn($movementType);

        $this->entityManager->method('getRepository')->willReturn($movementTypeRepo);

        // 3. Service
        $inventoryMovement = new InventoryMovements();
        $this->inventoryMovementService->expects($this->once())
            ->method('createInventoryMovement')
            ->with(
                $productVariant,
                10, // Before
                15, // After
                5,  // Quantity (15 - 10)
                $movementType,
                false
            )
            ->willReturn($inventoryMovement);

        // 4. Execute
        $this->useCase->executeNewProductVariant($productVariant, $stockBeforeMovement, $movementTypeId);
    }
}
