<?php
namespace App\Services\OrderService;

use App\Entity\ProductVariant;
use App\Entity\InventoryMovements;
use App\Entity\MovementType;
use App\Services\ProductVariantService\ProductVariantExistenceService;


class InventoryMovementService
{
    private $productVariantExistenceService;

    public function __construct(ProductVariantExistenceService $productVariantExistenceService)
    {
        $this->productVariantExistenceService = $productVariantExistenceService;
    }

    public function createInventoryMovement(
        bool $isCancellation = false,
        ProductVariant $productVariant,
        int $stockBeforeMovement,
        int $stockAfterMovement,
        int $quantity,
        MovementType $movementType
    ): InventoryMovements {
        // Utilisation du service pour vérifier si un variant similaire existe
        if (($this->productVariantExistenceService->doesVariantExist($productVariant)) && !$isCancellation) {
            throw new \Exception("Stock existant, vous pouvez modifier le stock de l'existant");
        }

        // Création du mouvement d'inventaire
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