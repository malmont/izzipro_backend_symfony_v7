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
use App\Controller\Admin\BaseTenantCrudController;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use Symfony\Component\HttpFoundation\RequestStack;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use App\Repository\ProductOptionValueRepository;

// 2. On étend notre contrôleur de base
class ProductVariantListController extends BaseTenantCrudController
{
    private RequestStack $requestStack;
    private UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase;
    private AdminUrlGenerator $adminUrlGenerator;


    public function __construct(
        TenantEntityManagerProvider $emProvider, 
        RequestStack $requestStack,
        UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase,
        AdminUrlGenerator $adminUrlGenerator 
    ) {
        parent::__construct($emProvider); 
        $this->requestStack = $requestStack;
        $this->updateStockAndInventoryUseCase = $updateStockAndInventoryUseCase;
        $this->adminUrlGenerator = $adminUrlGenerator;
    }

    public static function getEntityFqcn(): string
    {
        return ProductVariant::class;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $request = $this->requestStack->getCurrentRequest();
        $productId = $request->query->get('productId');
        
        $qb = $tenantEm->getRepository(ProductVariant::class)
            ->createQueryBuilder('pv');

        if ($productId) {
            $qb->where('pv.product = :productId')
               ->setParameter('productId', $productId);
        }
        
        return $qb;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
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
        $stockBeforeMovement = 0;
        $movementTypeId = 1;

        if ($entityInstance instanceof ProductVariant) {
            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, $stockBeforeMovement, $movementTypeId);
        }
        
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $stockBeforeMovement = 0;
        $movementTypeId = 4; // Ajustement de stock

        if ($entityInstance instanceof ProductVariant) {
            $unitOfWork = $tenantEm->getUnitOfWork();
            $originalData = $unitOfWork->getOriginalEntityData($entityInstance);

            if ($originalData && isset($originalData['stockQuantity'])) {
                $stockBeforeMovement = $originalData['stockQuantity'];
            }

            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, $stockBeforeMovement, $movementTypeId);
        }
        parent::updateEntity($entityManager, $entityInstance);
    }
}