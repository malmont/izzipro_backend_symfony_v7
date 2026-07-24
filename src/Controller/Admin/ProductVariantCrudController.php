<?php

namespace App\Controller\Admin;

use App\Entity\Color;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\Size;
use App\Repository\ColorRepository;
use App\Repository\ProductRepository;
use App\Repository\SizeRepository;
use App\Repository\ProductOptionValueRepository;
use App\Services\TenantEntityManagerProvider;
use App\Controller\Admin\BaseTenantCrudController; 
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;




class ProductVariantCrudController extends BaseTenantCrudController
{
    private UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase;
    private AdminUrlGenerator $adminUrlGenerator;
    public function __construct(
        TenantEntityManagerProvider $emProvider, 
        UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase,
        AdminUrlGenerator $adminUrlGenerator 
    ) {
        parent::__construct($emProvider); 
        $this->updateStockAndInventoryUseCase = $updateStockAndInventoryUseCase;
        $this->adminUrlGenerator = $adminUrlGenerator; 
    }

    public static function getEntityFqcn(): string
    {
        return ProductVariant::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            AssociationField::new('product', 'Product')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn($repo) => $repo->createQueryBuilder('p')->orderBy('p.name', 'ASC'),
                    'choice_label' => 'name',
                ]),
            AssociationField::new('color', 'Color')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn($repo) => $repo->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
                    'choice_label' => 'name',
                ]),
            AssociationField::new('size', 'Size')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn($repo) => $repo->createQueryBuilder('s')->orderBy('s.name', 'ASC'),
                    'choice_label' => 'name',
                ]),
            IntegerField::new('stockQuantity', 'Stock Quantity'),
            AssociationField::new('optionValues', 'Valeurs de cette variante')
                ->setHelp('Sélectionnez la combinaison exacte de valeurs pour cette variante (ex: "Rouge" et "XL").')
                ->setFormTypeOption('by_reference', false) 
                ->setFormTypeOptions([
                    'em' => $tenantEm, 
                    'query_builder' => fn($repo) => $repo->createQueryBuilder('pov')
                        ->join('pov.productOption', 'po')
                        ->orderBy('po.name', 'ASC')
                        ->addOrderBy('pov.value', 'ASC'),
                ])->onlyOnForms(),
                 AssociationField::new('optionValues', 'Valeurs')
                ->formatValue(function ($value, ProductVariant $variant) {
                    $count = count($variant->getOptionValues());
                    if ($count === 0) {
                        return 'Aucune';
                    }
                    
                    $url = $this->adminUrlGenerator
                        ->setController(ProductOptionValueListController::class)
                        ->setAction('index')
                        ->set('variantId', $variant->getId())
                        ->generateUrl();
                    
                    return sprintf('<a href="%s">Voir les valeurs (%d)</a>', $url, $count);
                })
                ->renderAsHtml()->hideOnForm(),
        ];
    }
    
     public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ProductVariant) {
            $tenantEm = $this->emProvider->getEntityManager();
            if ($product = $entityInstance->getProduct()) {
                $entityInstance->setProduct($tenantEm->merge($product));
            }
            if ($color = $entityInstance->getColor()) {
                $entityInstance->setColor($tenantEm->merge($color));
            }
            if ($size = $entityInstance->getSize()) {
                $entityInstance->setSize($tenantEm->merge($size));
            }
            foreach ($entityInstance->getOptionValues() as $optionValue) {
                $tenantEm->merge($optionValue);
            }
            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, 0, 1);
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    /**
     * Correction pour la MISE À JOUR
     */
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ProductVariant) {
            $tenantEm = $this->emProvider->getEntityManager();

             if ($product = $entityInstance->getProduct()) {
                $entityInstance->setProduct($tenantEm->merge($product));
            }
            if ($color = $entityInstance->getColor()) {
                $entityInstance->setColor($tenantEm->merge($color));
            }
            if ($size = $entityInstance->getSize()) {
                $entityInstance->setSize($tenantEm->merge($size));
            }
            foreach ($entityInstance->getOptionValues() as $optionValue) {
                $tenantEm->merge($optionValue);
            }
            $unitOfWork = $tenantEm->getUnitOfWork();
            $originalData = $unitOfWork->getOriginalEntityData($entityInstance);
            $stockBeforeMovement = $originalData['stockQuantity'] ?? 0;
            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, $stockBeforeMovement, 4);
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ProductVariant) {
             $tenantEm = $this->emProvider->getEntityManager();
             $managedVariant = $tenantEm->merge($entityInstance); 

            $this->updateStockAndInventoryUseCase->executeNewProductVariant($managedVariant, $managedVariant->getStockQuantity(), 2);
        }
        parent::deleteEntity($entityManager, $entityInstance);
    }

    public function configureActions(Actions $actions): Actions
    {
        $editCustom = Action::new('edit_custom', 'Modifier', 'fa fa-pencil')
            ->linkToRoute('admin_product_variant_edit_custom', function (ProductVariant $variant): array {
                return ['id' => $variant->getId()];
            });

        return $actions
            // On remplace l'action 'edit' par la nôtre sur la page de liste
            ->remove(Crud::PAGE_INDEX, Action::EDIT)
            ->add(Crud::PAGE_INDEX, $editCustom)
            // On peut aussi la retirer de la page de détail si besoin
            ->remove(Crud::PAGE_DETAIL, Action::EDIT);
    }
}