<?php
namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\ProductVariant;
use App\Repository\OrderRepository;
use App\Repository\ProductVariantRepository;
use App\Services\TenantEntityManagerProvider;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;

class OrderItemsCrudController extends AbstractCrudController
{
    /**
     * 1. On injecte notre provider
     */
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public static function getEntityFqcn(): string
    {
        return OrderItems::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            
            // ✅ On force le QueryBuilder et l'EM pour les champs de relation
            AssociationField::new('orderAssociated', 'Order')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (OrderRepository $repo) {
                        return $repo->createQueryBuilder('o')->orderBy('o.orderDate', 'DESC');
                    },
                    'choice_label' => 'reference',
                ]),
            AssociationField::new('productVariant', 'Product Variant')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (ProductVariantRepository $repo) {
                        return $repo->createQueryBuilder('pv')->orderBy('pv.id', 'ASC');
                    },
                    'choice_label' => 'id', // ou une autre propriété de ProductVariant
                ]),

            IntegerField::new('quantity', 'Quantity'),
            MoneyField::new('unitPrice', 'Unit Price')->setCurrency('USD')->setStoredAsCents(false),
            MoneyField::new('totalPrice', 'Total Price')->setCurrency('USD')->setStoredAsCents(false),
        ];
    }

    /**
     * 2. On surcharge les méthodes CRUD pour utiliser l'EM du tenant
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(OrderItems::class)->createQueryBuilder('entity');
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->persist($entityInstance);
        $tenantEm->flush();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->merge($entityInstance);
        $tenantEm->flush();
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $managedEntity = $tenantEm->merge($entityInstance);
        $tenantEm->remove($managedEntity);
        $tenantEm->flush();
    }
}