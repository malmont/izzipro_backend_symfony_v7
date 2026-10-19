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
            // Coupures servies au POS (19/10/2026, GET /api/caisse/denominations)
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('valueCents', 'Valeur (cents)')->setHelp('Utilisée par la caisse ; vide : calculée depuis la valeur'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('kind', 'Genre')->setChoices(['Billet' => 'bill', 'Pièce' => 'coin'])->setRequired(false),
            \EasyCorp\Bundle\EasyAdminBundle\Field\TextField::new('currency', 'Devise (ISO)')->setHelp('CAD, EUR…'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('position', 'Ordre'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField::new('active', 'Active')->renderAsSwitch(false),

        ];
    }
}
