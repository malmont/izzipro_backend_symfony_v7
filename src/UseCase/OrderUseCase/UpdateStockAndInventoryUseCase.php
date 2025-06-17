<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\ProductVariant;
use App\Entity\MovementType;
use App\Services\OrderService\InventoryMovementService;
use App\Services\TenantEntityManagerProvider; 

class UpdateStockAndInventoryUseCase
{
    // MODIFICATION 1 : Le constructeur est refactorisé
    private TenantEntityManagerProvider $emProvider;
    private InventoryMovementService $inventoryMovementService;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        InventoryMovementService $inventoryMovementService
    ) {
        $this->emProvider = $emProvider;
        $this->inventoryMovementService = $inventoryMovementService;
    }

    public function execute(ProductVariant $productVariant, int $quantity, bool $isCancellation = false): void
    {
        // MODIFICATION 2 : On récupère l'EM du tenant ici
        $em = $this->emProvider->getEntityManager();
        $movementTypeRepository = $em->getRepository(MovementType::class);

        $stockBeforeMovement = $productVariant->getStockQuantity();
        $newQuantity = $isCancellation ? $quantity : -$quantity;
        $productVariant->setStockQuantity($stockBeforeMovement + $newQuantity);

        // On utilise l'EM du tenant pour la persistance
        $em->persist($productVariant);

        $stockAfterMovement = $productVariant->getStockQuantity();
        // On récupère le type de mouvement depuis le bon repository
        $movementType = $movementTypeRepository->find($isCancellation ? 1 : 2);

        $inventoryMovement = $this->inventoryMovementService->createInventoryMovement(
            $productVariant,
            $stockBeforeMovement,
            $stockAfterMovement,
            $quantity,
            $movementType,
            $isCancellation,
        );

        // On utilise l'EM du tenant pour la persistance
        $em->persist($inventoryMovement);
    }

    public function executeNewProductVariant(ProductVariant $productVariant, int $stockBeforeMovement, int $movementTypeId): void
    {
        $em = $this->emProvider->getEntityManager();
        $movementTypeRepository = $em->getRepository(MovementType::class);

        $stockAfterMovement = $productVariant->getStockQuantity();

        if ($stockBeforeMovement > $stockAfterMovement) {
            $quantity = $stockBeforeMovement - $stockAfterMovement;
            $quantity = $quantity * -1;
        } else {
            $quantity = $stockAfterMovement - $stockBeforeMovement;
        }
        
        $movementType = $movementTypeRepository->find($movementTypeId);

        $inventoryMovement = $this->inventoryMovementService->createInventoryMovement(
            $productVariant,
            $stockBeforeMovement,
            $stockAfterMovement,
            $quantity,
            $movementType,
            false,
        );
        $em->persist($inventoryMovement);
    }
}