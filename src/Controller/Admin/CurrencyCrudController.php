<?php

namespace App\Controller\Admin;

use App\Entity\Currency;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class CurrencyCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Currency::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Devise')
            ->setEntityLabelInPlural('Devises')
            ->setPageTitle('index', 'Gestion des Devises')
            ->setDefaultSort(['code' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('code', 'Code ISO')
                ->setHelp('Code standard ISO 4217 (ex: USD, CAD, EUR)')
                ->formatValue(function ($value) {
                    return $value ? strtoupper($value) : '';
                }),

            TextField::new('name', 'Nom de la devise'),

            TextField::new('symbol', 'Symbole')
                ->setColumns(2),

            NumberField::new('exchangeRate', 'Taux (vs Base)')
                ->setNumDecimals(6)
                ->setHelp('1 Base = X Devise. Mis à jour automatiquement par l\'API.'),

            DateTimeField::new('updatedAt', 'Dernière MAJ')
                ->hideOnForm()
                ->setFormat('dd/MM/yyyy HH:mm'),
        ];
    }
}
