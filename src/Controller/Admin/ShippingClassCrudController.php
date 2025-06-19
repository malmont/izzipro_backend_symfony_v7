<?php
namespace App\Controller\Admin;

use App\Entity\ShippingClass;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;

// 2. On étend notre contrôleur de base
class ShippingClassCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return ShippingClass::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom')
                ->setRequired(true),
            TextareaField::new('description', 'Description')
                ->setRequired(false),
        ];
    }
}