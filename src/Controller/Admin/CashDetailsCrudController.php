<?php

namespace App\Controller\Admin;

use App\Entity\CashDetails;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

class CashDetailsCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CashDetails::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            AssociationField::new('transactionCaisse', 'Transaction')
                ->setCrudController(TransactionCaisseCrudController::class),
            AssociationField::new('typeCash', 'Type de cash')
                ->setCrudController(TypeCashCrudController::class),
            IntegerField::new('nombreItems', 'Nombre d\'items'),
        ];
    }
}
