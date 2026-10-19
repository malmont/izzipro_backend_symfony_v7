<?php
namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\OrderTax;
use App\Entity\Tax;
use App\Repository\OrderRepository;
use App\Repository\TaxRepository;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;


// 2. On étend notre contrôleur de base
class OrderTaxCrudController extends BaseTenantCrudController
{
    use CashRecordLockTrait; // données de caisse non modifiables (C2)

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
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            
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

            MoneyField::new('amount', 'Amount')->setCurrency('USD')
        ];
    }

    protected function isCashRecordLocked(object $entity): bool
    {
        return $entity instanceof \App\Entity\OrderTax && self::isCounterSale($entity->getOrderTax());
    }

    public function configureActions(\EasyCorp\Bundle\EasyAdminBundle\Config\Actions $actions): \EasyCorp\Bundle\EasyAdminBundle\Config\Actions
    {
        return $this->lockCashRecordActions(parent::configureActions($actions));
    }

    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyLockedCashRecord($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyLockedCashRecord($entityInstance);
        parent::deleteEntity($entityManager, $entityInstance);
    }
}
