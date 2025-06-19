<?php

namespace App\Controller\Admin;

use App\Entity\TransactionType;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;

class TransactionTypeCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return TransactionType::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom'),
            TextareaField::new('description', 'Description')->hideOnIndex(),
            AssociationField::new('transactionCaisses', 'Caisses Associées')->hideOnForm(),
        ];
    }
}
