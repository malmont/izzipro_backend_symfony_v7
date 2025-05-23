<?php

namespace App\Controller\Admin;

use App\Entity\ShippingOrder;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;

class ShippingOrderCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ShippingOrder::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('odershipping', 'Commande'),
            TextField::new('carrierAccountId', 'Account ID'),
            TextField::new('service', 'Service'),
            MoneyField::new('totalPrice', 'Prix total')->setCurrency('USD'),
            DateTimeField::new('createdAt', 'Date')->onlyOnIndex(),
            AssociationField::new('parcels', 'Colis')->hideOnForm(),
        ];
    }
}
