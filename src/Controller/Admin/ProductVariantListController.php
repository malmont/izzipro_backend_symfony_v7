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
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use Symfony\Component\HttpFoundation\RequestStack;

// 2. On étend notre contrôleur de base
class ProductVariantListController extends BaseTenantCrudController
{
    private RequestStack $requestStack;
    private UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase;

    // 3. Le constructeur appelle le parent et stocke ses propres dépendances
    public function __construct(
        TenantEntityManagerProvider $emProvider, // Requis par le parent
        RequestStack $requestStack,
        UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase
    ) {
        parent::__construct($emProvider); // On passe la dépendance au parent
        $this->requestStack = $requestStack;
        $this->updateStockAndInventoryUseCase = $updateStockAndInventoryUseCase;
    }

    public static function getEntityFqcn(): string
    {
        return ProductVariant::class;
    }

    // 4. On CONSERVE createIndexQueryBuilder car il a une logique de filtre personnalisée
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

    // 5. On CONSERVE configureFields car il est spécifique à cette entité
    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
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

    // 6. On CONSERVE les méthodes d'écriture car elles ont une logique métier personnalisée
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $stockBeforeMovement = 0;
        $movementTypeId = 1; // Entrée de stock

        if ($entityInstance instanceof ProductVariant) {
            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, $stockBeforeMovement, $movementTypeId);
        }
        
        // On appelle le parent pour faire le persist et le flush
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

        // On appelle le parent pour faire le merge et le flush
        parent::updateEntity($entityManager, $entityInstance);
    }
}