<?php

namespace App\Controller\Admin;

use App\Entity\OrderItems;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;

class OrderItemsCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return OrderItems::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            AssociationField::new('orderAssociated', 'Order'),
            AssociationField::new('productVariant', 'Product Variant'),
            IntegerField::new('quantity', 'Quantity'),
            MoneyField::new('unitPrice', 'Unit Price')->setCurrency('USD'),
            MoneyField::new('totalPrice', 'Total Price')->setCurrency('USD'),
        ];
    }
}
