<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;

class OrderCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Order::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('reference', 'Reference'),
            AssociationField::new('userId', 'Client'),
            AssociationField::new('carrier', 'Carrier'),
            AssociationField::new('shippingAdress', 'Shipping Address'),
            AssociationField::new('orderSource', 'Order Source'),
            AssociationField::new('status', 'Order Status')
                ->formatValue(function ($value, $entity) {
                    return $entity->getStatus() ? $entity->getStatus()->getName() : '';
                }),
            DateField::new('orderDate', 'Order Date'),
            MoneyField::new('totalAmount', 'Total Amount')->setCurrency('USD'),
            CollectionField::new('orderItems', 'Items')->onlyOnDetail(),
            CollectionField::new('payments', 'Payments')->onlyOnDetail(),
            CollectionField::new('transactionCaisses', 'Caisse Transactions')->onlyOnDetail(),
        ];
    }
}
