<?php

namespace App\Controller\Admin;

use App\Entity\CashDetails;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

class CashDetailsCrudController extends BaseTenantCrudController
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
