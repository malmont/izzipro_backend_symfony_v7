<?php
namespace App\UseCase\OrderUseCase;

use App\Services\OrderService\InventoryMovementService;
use App\Entity\ProductVariant;
use App\Entity\InventoryMovements;
use App\Entity\OrderItems;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\MovementTypeRepository;

class UpdateStockAndInventoryUseCase
{
    private $em;
    private $movementTypeRepository;
    private $inventoryMovementService;

    public function __construct(
        EntityManagerInterface $em,
        MovementTypeRepository $movementTypeRepository,
        InventoryMovementService $inventoryMovementService
    ) {
        $this->em = $em;
        $this->movementTypeRepository = $movementTypeRepository;
        $this->inventoryMovementService = $inventoryMovementService;
    }

    public function execute(ProductVariant $productVariant, int $quantity, bool $isCancellation = false): void
    {
        $stockBeforeMovement = $productVariant->getStockQuantity();
        $newQuantity = $isCancellation ? $quantity : -$quantity;
        $productVariant->setStockQuantity($stockBeforeMovement + $newQuantity);

        // Persister la mise à jour du produit
        $this->em->persist($productVariant);

        $stockAfterMovement = $productVariant->getStockQuantity();
        $movementType = $this->movementTypeRepository->find($isCancellation ? 1 : 2);

        // Création du mouvement d'inventaire via le service
        $inventoryMovement = $this->inventoryMovementService->createInventoryMovement(
            $productVariant,
            $stockBeforeMovement,
            $stockAfterMovement,
            $quantity,
            $movementType
        );

        // Persister le mouvement d'inventaire
        $this->em->persist($inventoryMovement);

      
    }
}