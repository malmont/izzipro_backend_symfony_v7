<?php
namespace App\Controller\Admin;

use App\Entity\PaymentType;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;

// 2. On étend notre contrôleur de base
class PaymentTypeCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return PaymentType::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Name'),
            TextField::new('description', 'Description')->hideOnIndex(),
            CollectionField::new('payments', 'Payments')->onlyOnDetail(),
        ];
    }
}