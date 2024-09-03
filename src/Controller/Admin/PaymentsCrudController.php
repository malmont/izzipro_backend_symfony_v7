<?php
namespace App\Controller\Admin;

use App\Entity\Payments;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;

class PaymentsCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Payments::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Payment')
                    ->setEntityLabelInPlural('Payments')
                    ->setSearchFields(['id', 'amount', 'paymentDate']);
    }

    public function configureFields(string $pageName): iterable
{
    $fields = [
        IdField::new('id')->hideOnForm(),
        AssociationField::new('orderPayment', 'Order')->setRequired(true),
        MoneyField::new('amount', 'Amount')->setCurrency('USD'),
        DateTimeField::new('paymentDate', 'Payment Date'),
        AssociationField::new('paymentMethod', 'Payment Method')->setRequired(true),
        AssociationField::new('statutPayment', 'Payment Status')->setRequired(true),
        AssociationField::new('paymentType', 'Payment Type')->setRequired(true),
    ];

    // Affichez la transaction caisse seulement si elle n'est pas null
    if ($pageName === Crud::PAGE_DETAIL && $this->getSubject()->getTransactionCaisses()->count() > 0) {
        $fields[] = CollectionField::new('transactionCaisses', 'Transaction Caisse')
            ->hideOnForm()
            ->allowAdd(false)
            ->allowDelete(false);
    }

    return $fields;
}
}
