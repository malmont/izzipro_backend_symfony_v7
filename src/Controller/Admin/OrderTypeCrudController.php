<?php
namespace App\Controller\Admin;

use App\Entity\OrderType;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;

class OrderTypeCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return OrderType::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Name'),
            TextField::new('description', 'Description')->hideOnIndex(),
            CollectionField::new('orders', 'Orders')->onlyOnDetail(),
        ];
    }
}
