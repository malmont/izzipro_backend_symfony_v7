<?php

namespace App\Controller\Admin;

use App\Entity\Payments;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
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
                    ->setSearchFields(['id', 'amount', 'paymentDate', 'squarePaymentId', 'squareStatus']);
    }

    public function configureFields(string $pageName): iterable
    {
        $fields = [
            IdField::new('id')->hideOnForm(),

            // 🔗 Association avec la commande
            AssociationField::new('orderPayment', 'Order')->setRequired(true),

            // 💰 Montant du paiement
            MoneyField::new('amount', 'Amount')->setCurrency('USD'),

            // 📅 Date du paiement
            DateTimeField::new('paymentDate', 'Payment Date'),

            // 💳 Méthode de paiement
            AssociationField::new('paymentMethod', 'Payment Method')->setRequired(true),
            AssociationField::new('statutPayment', 'Payment Status')->setRequired(true),
            AssociationField::new('paymentType', 'Payment Type')->setRequired(true),

            // ✅ Nouveau : Infos Square
            TextField::new('squarePaymentId', 'Square Payment ID')->hideOnForm(),
            TextField::new('squareOrderId', 'Square Order ID')->hideOnForm(),
            UrlField::new('squareReceiptUrl', 'Receipt URL')->hideOnIndex(),
            TextField::new('squareStatus', 'Payment Status')->hideOnForm(),
            TextField::new('squareCardBrand', 'Card Brand')->hideOnForm(),
            TextField::new('squareLast4', 'Card Last 4')->hideOnForm(),
            TextField::new('squareRiskLevel', 'Risk Level')->hideOnForm(),
        ];

        // 💼 Affichage conditionnel de la transaction caisse
        if ($pageName === Crud::PAGE_DETAIL && $this->getSubject()->getTransactionCaisses()->count() > 0) {
            $fields[] = CollectionField::new('transactionCaisses', 'Transaction Caisse')
                ->hideOnForm()
                ->allowAdd(false)
                ->allowDelete(false);
        }

        return $fields;
    }
}
