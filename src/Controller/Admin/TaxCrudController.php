<?php
namespace App\Controller\Admin;

use App\Entity\Tax;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;

// 2. On étend notre contrôleur de base
class TaxCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Tax::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Tax')
                    ->setEntityLabelInPlural('Taxes')
                    ->setSearchFields(['name', 'province', 'type']);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Name'),
            NumberField::new('rate', 'Rate')->setNumDecimals(2),
            TextField::new('type', 'Type'),
            TextField::new('province', 'Province')->hideOnIndex(),
            AssociationField::new('orderTaxes', 'Order Taxes')->hideOnForm()
        ];
    }
}