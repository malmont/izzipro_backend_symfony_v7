<?php

namespace App\Controller\Admin;

use App\Entity\TypeCash;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;

class TypeCashCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return TypeCash::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom')
                ->setHelp('Exemple : Billet 100$, Billet 50$, Pièce 2$')
                ->setRequired(true),
            MoneyField::new('value', 'Valeur')->setCurrency('USD'),

        ];
    }
}
