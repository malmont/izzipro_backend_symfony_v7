<?php
namespace App\Controller\Admin;

use App\Entity\OrderTax;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;

class OrderTaxCrudController extends AbstractCrudController
{
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
        return [
            IdField::new('id')->hideOnForm(),
            AssociationField::new('orderTax', 'Order')
                ->setFormTypeOption('choice_label', 'reference')
                ->setFormTypeOption('query_builder', function($repository) {
                    return $repository->createQueryBuilder('o')
                        ->orderBy('o.reference', 'ASC');
                }),
            AssociationField::new('tax', 'Tax')
                ->setFormTypeOption('choice_label', 'name')
                ->setFormTypeOption('query_builder', function($repository) {
                    return $repository->createQueryBuilder('t')
                        ->orderBy('t.name', 'ASC');
                }),
            MoneyField::new('amount', 'Amount')->setCurrency('USD'),
        ];
    }
}
