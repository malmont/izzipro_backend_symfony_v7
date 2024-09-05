<?php
namespace App\Controller\Admin;

use App\Entity\ProductVariant;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

class ProductVariantCrudController extends AbstractCrudController
{
    private $entityManager;
    private $updateStockAndInventoryUseCase;

    public function __construct(EntityManagerInterface $entityManager, UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase)
    {
        $this->entityManager = $entityManager;
        $this->updateStockAndInventoryUseCase = $updateStockAndInventoryUseCase;
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
        $stockBeforeMovement=0;
        $movementTypeId=1;
        if ($entityInstance instanceof ProductVariant) {

            // Utilisation du use case pour la création du mouvement d'inventaire
            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance,  $stockBeforeMovement, $movementTypeId);
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $stockBeforeMovement = 0;
        $movementTypeId = 4;

        if ($entityInstance instanceof ProductVariant) {
 
            $unitOfWork = $this->entityManager->getUnitOfWork();
            $originalData = $unitOfWork->getOriginalEntityData($entityInstance);

            if ($originalData && isset($originalData['stockQuantity'])) {
                $stockBeforeMovement = $originalData['stockQuantity']; 
            }

            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, $stockBeforeMovement, $movementTypeId);
        }

        parent::updateEntity($entityManager, $entityInstance);
    }
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $movementTypeId = 2;
        $stockBeforeMovement = 0;


        if ($entityInstance instanceof ProductVariant) {

            $oldVariant = $this->entityManager->getRepository(ProductVariant::class)->find($entityInstance->getId());


            if ($oldVariant) {
                $stockBeforeMovement = $oldVariant->getStockQuantity();
                $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, $stockBeforeMovement, $movementTypeId);
                $entityManager->flush();
            }
        }

        // Supprimez ensuite l'entité ProductVariant
        parent::deleteEntity($entityManager, $entityInstance);
    }
}
