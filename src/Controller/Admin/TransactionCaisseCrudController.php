<?php
namespace App\Controller\Admin;

use App\Entity\TransactionCaisse;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;

class TransactionCaisseCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TransactionCaisse::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            AssociationField::new('caisse'),
            AssociationField::new('userCaisse')->setLabel('User'),
            AssociationField::new('orderCaisse')->setLabel('Order')->hideOnIndex(),
            AssociationField::new('payment')->setLabel('Payment')->hideOnIndex(),
            DateTimeField::new('transactionDate')->setLabel('Transaction Date'),
            MoneyField::new('amount')->setCurrency('USD'),
            AssociationField::new('transactionType')->setLabel('Transaction Type'),
        ];
    }
}
