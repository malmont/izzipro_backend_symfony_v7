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
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use Symfony\Component\HttpFoundation\RequestStack;

class ProductVariantListController extends AbstractCrudController
{
    // MODIFICATION 1 : On injecte notre provider
    private TenantEntityManagerProvider $emProvider;
    private RequestStack $requestStack;
    private UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        RequestStack $requestStack,
        UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase
    ) {
        $this->emProvider = $emProvider;
        $this->requestStack = $requestStack;
        $this->updateStockAndInventoryUseCase = $updateStockAndInventoryUseCase;
    }

    public static function getEntityFqcn(): string
    {
        return ProductVariant::class;
    }

    // MODIFICATION 2 : La requête de liste utilise l'EM du tenant
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
        // MODIFICATION 3 : Les champs d'association sont rendus "tenant-aware"
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

    // MODIFICATION 4 : La création utilise l'EM du tenant
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();

        $stockBeforeMovement = 0;
        $movementTypeId = 1; // Entrée de stock

        if ($entityInstance instanceof ProductVariant) {
            // L'UseCase est maintenant appelé dans un contexte où l'EM sera celui du tenant
            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, $stockBeforeMovement, $movementTypeId);
        }
        
        // On persiste et flush avec l'EM du tenant, on n'appelle pas le parent.
        $tenantEm->persist($entityInstance);
        $tenantEm->flush();
    }

    // MODIFICATION 5 : La mise à jour utilise l'EM du tenant
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();

        if ($entityInstance instanceof ProductVariant) {
            $unitOfWork = $tenantEm->getUnitOfWork();
            $originalData = $unitOfWork->getOriginalEntityData($entityInstance);

            $stockBeforeMovement = $originalData['stockQuantity'] ?? 0;
            $movementTypeId = 4; // Ajustement de stock

            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, $stockBeforeMovement, $movementTypeId);
        }

        // On s'assure que l'entité est bien gérée par notre EM et on sauvegarde
        $tenantEm->merge($entityInstance);
        $tenantEm->flush();
    }
}