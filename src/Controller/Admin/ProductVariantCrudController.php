<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\Color;
use App\Entity\Size;
use App\Repository\ProductRepository;
use App\Repository\ColorRepository;
use App\Repository\SizeRepository;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

class ProductVariantCrudController extends AbstractCrudController
{
    // MODIFICATION 1 : On injecte notre provider
    private TenantEntityManagerProvider $emProvider;
    private UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase
    ) {
        $this->emProvider = $emProvider;
        $this->updateStockAndInventoryUseCase = $updateStockAndInventoryUseCase;
    }

    public static function getEntityFqcn(): string
    {
        return ProductVariant::class;
    }

    public function configureFields(string $pageName): iterable
    {
        // MODIFICATION 2 : Les champs d'association sont rendus "tenant-aware"
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
    
    // MODIFICATION 3 : On surcharge toutes les méthodes CRUD
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(ProductVariant::class)->createQueryBuilder('pv');
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $stockBeforeMovement = 0;
        $movementTypeId = 1; // Entrée de stock

        if ($entityInstance instanceof ProductVariant) {
            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, $stockBeforeMovement, $movementTypeId);
        }

        $tenantEm->persist($entityInstance);
        $tenantEm->flush();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $stockBeforeMovement = 0;
        $movementTypeId = 4; // Ajustement de stock

        if ($entityInstance instanceof ProductVariant) {
            // Pour obtenir l'ancienne valeur, on recharge l'entité depuis la BDD du tenant
            $originalVariant = $tenantEm->getUnitOfWork()->getOriginalEntityData($entityInstance);
            if ($originalVariant && isset($originalVariant['stockQuantity'])) {
                $stockBeforeMovement = $originalVariant['stockQuantity'];
            }

            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, $stockBeforeMovement, $movementTypeId);
        }

        $tenantEm->merge($entityInstance); // On utilise merge pour la mise à jour
        $tenantEm->flush();
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $movementTypeId = 2; // Sortie de stock

        if ($entityInstance instanceof ProductVariant) {
            // On s'assure que l'entité est bien gérée par l'EM du tenant avant de lire ses propriétés
            $managedVariant = $tenantEm->merge($entityInstance);
            $stockBeforeMovement = $managedVariant->getStockQuantity();
            
            // On passe l'entité managée au UseCase
            $this->updateStockAndInventoryUseCase->executeNewProductVariant($managedVariant, $stockBeforeMovement, $movementTypeId);
            
            // On supprime l'entité
            $tenantEm->remove($managedVariant);
            $tenantEm->flush();
        }
    }
}