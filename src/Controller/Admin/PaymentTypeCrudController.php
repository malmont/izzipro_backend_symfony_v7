<?php
namespace App\Controller\Admin;

use App\Entity\PaymentType;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;

class PaymentTypeCrudController extends AbstractCrudController
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
