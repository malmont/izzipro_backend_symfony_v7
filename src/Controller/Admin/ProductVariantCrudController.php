<?php
namespace App\Controller\Admin;

use App\Entity\ProductVariant;
use App\Entity\InventoryMovements;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

class ProductVariantCrudController extends AbstractCrudController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public static function getEntityFqcn(): string
    {
        return ProductVariant::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            AssociationField::new('product', 'Product'),
            AssociationField::new('color', 'Color'),
            AssociationField::new('size', 'Size'),
            IntegerField::new('stockQuantity', 'Stock Quantity'),
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ProductVariant) {
            // Mouvement d'inventaire pour la création
            $this->createInventoryMovement($entityInstance, 1); // ID 1 pour 'Entrant'
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ProductVariant) {
            // Récupérer l'ancienne quantité en stock avant la modification
            $oldVariant = $this->entityManager->getRepository(ProductVariant::class)->find($entityInstance->getId());

            if ($oldVariant) {
                $oldStockQuantity = $oldVariant->getStockQuantity();
                $newStockQuantity = $entityInstance->getStockQuantity();

                if ($newStockQuantity !== $oldStockQuantity) {
                    // Créer un mouvement d'inventaire en fonction de l'ajustement
                    $this->createInventoryMovement($entityInstance, $newStockQuantity > $oldStockQuantity ? 1 : 4, $oldStockQuantity);
                }
            }
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ProductVariant) {
            // Mouvement d'inventaire pour la suppression
            $this->createInventoryMovement($entityInstance, 2); // ID 2 pour 'Sortant'
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    private function createInventoryMovement(ProductVariant $variant, int $movementTypeId, ?int $oldStockQuantity = null): void
    {
        $movementType = $this->entityManager->getRepository(MovementType::class)->find($movementTypeId);

        if (!$movementType) {
            throw new \Exception("Movement type with ID '{$movementTypeId}' not found");
        }

        $inventoryMovement = new InventoryMovements();
        $inventoryMovement->setProductVariant($variant);
        $inventoryMovement->setMovementType($movementType);
        $inventoryMovement->setQuantity(abs($variant->getStockQuantity() - ($oldStockQuantity ?? 0)));
        $inventoryMovement->setMovementDate(new \DateTime());

        if ($movementTypeId === 1) { // Entrant
            $inventoryMovement->setStockBeforeMovement($oldStockQuantity ?? 0); // Pour la création, on part de 0
            $inventoryMovement->setStockAfterMovement($variant->getStockQuantity());
        } elseif ($movementTypeId === 2) { // Sortant
            $inventoryMovement->setStockBeforeMovement($variant->getStockQuantity());
            $inventoryMovement->setStockAfterMovement(0); // Suppression, donc stock après = 0
        } elseif ($movementTypeId === 4) { // Ajustement
            $inventoryMovement->setStockBeforeMovement($oldStockQuantity);
            $inventoryMovement->setStockAfterMovement($variant->getStockQuantity());
        }

        $this->entityManager->persist($inventoryMovement);
        $this->entityManager->flush();
    }
}
