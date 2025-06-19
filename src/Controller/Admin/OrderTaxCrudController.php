<?php
namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\OrderTax;
use App\Entity\Tax;
use App\Repository\OrderRepository;
use App\Repository\TaxRepository;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;

class OrderTaxCrudController extends AbstractCrudController
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
        return OrderTax::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Order Tax')
                    ->setEntityLabelInPlural('Order Taxes')
                    ->setSearchFields(['amount', 'orderTax.reference', 'tax.name']);
    }

    public function configureFields(string $pageName): iterable
    {
        // On récupère l'EM du tenant une seule fois
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),

            // ✅ On force l'EM pour que le QueryBuilder soit correct
            AssociationField::new('orderTax', 'Order')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (OrderRepository $repo) {
                        return $repo->createQueryBuilder('o')->orderBy('o.reference', 'ASC');
                    },
                    'choice_label' => 'reference',
                ]),

            AssociationField::new('tax', 'Tax')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (TaxRepository $repo) {
                        return $repo->createQueryBuilder('t')->orderBy('t.name', 'ASC');
                    },
                    'choice_label' => 'name',
                ]),

            MoneyField::new('amount', 'Amount')->setCurrency('USD')->setStoredAsCents(false),
        ];
    }
    
    /**
     * 2. On surcharge les méthodes CRUD pour utiliser l'EM du tenant
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(OrderTax::class)->createQueryBuilder('entity');
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