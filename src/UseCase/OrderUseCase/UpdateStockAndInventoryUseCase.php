<?php
namespace App\UseCase\OrderUseCase;


use App\Entity\ProductVariant;
use App\Entity\InventoryMovements;
use App\Entity\OrderItems;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\MovementTypeRepository;

class UpdateStockAndInventoryUseCase
{
    private $em;
    private $movementTypeRepository;

    public function __construct(EntityManagerInterface $em, MovementTypeRepository $movementTypeRepository)
    {
        $this->em = $em;
        $this->movementTypeRepository = $movementTypeRepository;
    }

    public function execute(ProductVariant $productVariant, int $quantity, bool $isCancellation = false): void
    {
        $stockBeforeMovement = $productVariant->getStockQuantity();
        $newQuantity = $isCancellation ? $quantity : -$quantity;
        $productVariant->setStockQuantity($stockBeforeMovement + $newQuantity);

        $this->em->persist($productVariant);

        $stockAfterMovement = $productVariant->getStockQuantity();
        $movementType = $this->movementTypeRepository->find($isCancellation ? 1 : 2);

        $inventoryMovement = new InventoryMovements();
        $inventoryMovement->setStockBeforeMovement($stockBeforeMovement);
        $inventoryMovement->setStockAfterMovement($stockAfterMovement);
        $inventoryMovement->setProductVariant($productVariant);
        $inventoryMovement->setQuantity($quantity);
        $inventoryMovement->setMovementType($movementType);
        $inventoryMovement->setMovementDate(new \DateTime());

        $this->em->persist($inventoryMovement);
    }
}