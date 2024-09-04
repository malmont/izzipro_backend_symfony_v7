<?php
namespace App\Services\OrderService;

use App\Entity\ProductVariant;
use App\Entity\InventoryMovements;
use App\Entity\MovementType;

class InventoryMovementService
{
    public function createInventoryMovement(
        ProductVariant $productVariant,
        int $stockBeforeMovement,
        int $stockAfterMovement,
        int $quantity,
        MovementType $movementType
    ): InventoryMovements {
        $inventoryMovement = new InventoryMovements();
        $inventoryMovement->setStockBeforeMovement($stockBeforeMovement);
        $inventoryMovement->setStockAfterMovement($stockAfterMovement);
        $inventoryMovement->setProductVariant($productVariant);
        $inventoryMovement->setQuantity($quantity);
        $inventoryMovement->setMovementType($movementType);
        $inventoryMovement->setMovementDate(new \DateTime());

        return $inventoryMovement;
    }
}
