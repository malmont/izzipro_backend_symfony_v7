<?php

namespace App\Controller\Admin;

use App\Entity\Color;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\Size;
use App\Repository\ColorRepository;
use App\Repository\ProductRepository;
use App\Repository\SizeRepository;
use App\Services\TenantEntityManagerProvider;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;


// 2. On étend notre contrôleur de base
class ProductVariantCrudController extends BaseTenantCrudController
{
    private UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase;

    // 3. Le constructeur appelle le parent et stocke ses propres dépendances
    public function __construct(
        TenantEntityManagerProvider $emProvider, // Requis par le parent
        UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase
    ) {
        parent::__construct($emProvider); // On passe la dépendance au parent
        $this->updateStockAndInventoryUseCase = $updateStockAndInventoryUseCase;
    }

    public static function getEntityFqcn(): string
    {
        return ProductVariant::class;
    }

    // 4. On conserve configureFields car il est spécifique
    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            AssociationField::new('product', 'Product')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(ProductRepository $repo) => $repo->createQueryBuilder('p')->orderBy('p.name', 'ASC'),
                    'choice_label' => 'name',
                ]),
            AssociationField::new('color', 'Color')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(ColorRepository $repo) => $repo->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
                    'choice_label' => 'name',
                ]),
            AssociationField::new('size', 'Size')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(SizeRepository $repo) => $repo->createQueryBuilder('s')->orderBy('s.name', 'ASC'),
                    'choice_label' => 'name',
                ]),
            IntegerField::new('stockQuantity', 'Stock Quantity'),
        ];
    }
    
    // La méthode createIndexQueryBuilder est SUPPRIMÉE (le parent s'en occupe bien)

    // 5. On CONSERVE les méthodes d'écriture car elles ont une logique métier,
    //    mais on les simplifie en appelant le parent pour la sauvegarde.
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ProductVariant) {
            // Logique métier personnalisée
            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, 0, 1);
        }
        
        // On laisse le parent gérer le persist et le flush
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ProductVariant) {
            $tenantEm = $this->emProvider->getEntityManager();
            $unitOfWork = $tenantEm->getUnitOfWork();
            $originalData = $unitOfWork->getOriginalEntityData($entityInstance);
            $stockBeforeMovement = $originalData['stockQuantity'] ?? 0;

            // Logique métier personnalisée
            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, $stockBeforeMovement, 4);
        }

        // On laisse le parent gérer le merge et le flush
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ProductVariant) {
            // Logique métier personnalisée AVANT la suppression
            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, $entityInstance->getStockQuantity(), 2);
        }

        // On laisse le parent gérer la suppression
        parent::deleteEntity($entityManager, $entityInstance);
    }
}