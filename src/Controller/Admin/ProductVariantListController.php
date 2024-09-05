<?php
namespace App\Controller\Admin;

use App\Entity\ProductVariant;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

class ProductVariantListController extends AbstractCrudController
{
    private $em;
    private $requestStack;
    private $updateStockAndInventoryUseCase;

    public function __construct(EntityManagerInterface $em, RequestStack $requestStack, UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase)
    {
        $this->em = $em;
        $this->requestStack = $requestStack;
        $this->updateStockAndInventoryUseCase = $updateStockAndInventoryUseCase;
    }

    public static function getEntityFqcn(): string
    {
        return ProductVariant::class;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $request = $this->requestStack->getCurrentRequest();
        $productId = $request->query->get('productId');
        
        return $this->em->getRepository(ProductVariant::class)
            ->createQueryBuilder('pv')
            ->where('pv.product = :productId')
            ->setParameter('productId', $productId);
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
        $request = $this->requestStack->getCurrentRequest();
        $productId = $request->query->get('productId');

        $stockBeforeMovement = 0;
        $movementTypeId = 1; // Entrée de stock

        if ($entityInstance instanceof ProductVariant) {
            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, $stockBeforeMovement, $movementTypeId);
        }

        parent::persistEntity($entityManager, $entityInstance);
    
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $productId = $request->query->get('productId');

        $stockBeforeMovement = 0;
        $movementTypeId = 4; // Ajustement de stock

        if ($entityInstance instanceof ProductVariant) {
            $unitOfWork = $this->em->getUnitOfWork();
            $originalData = $unitOfWork->getOriginalEntityData($entityInstance);

            if ($originalData && isset($originalData['stockQuantity'])) {
                $stockBeforeMovement = $originalData['stockQuantity'];
            }

            $this->updateStockAndInventoryUseCase->executeNewProductVariant($entityInstance, $stockBeforeMovement, $movementTypeId);
        }

        parent::updateEntity($entityManager, $entityInstance);

    }


}
