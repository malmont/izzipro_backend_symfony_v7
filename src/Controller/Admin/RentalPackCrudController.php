<?php

namespace App\Controller\Admin;

use App\Entity\RentalPack;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;

class RentalPackCrudController extends BaseTenantCrudController
{
    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return RentalPack::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Nom du Pack');
        
        yield MoneyField::new('hourRate', 'Tarif Horaire ($)')->setCurrency('USD')->setStoredAsCents(true);
        yield MoneyField::new('halfDayRate', 'Tarif Demi-Journée ($)')->setCurrency('USD')->setStoredAsCents(true);
        yield MoneyField::new('dayRate', 'Tarif Journalier ($)')->setCurrency('USD')->setStoredAsCents(true);
        yield MoneyField::new('weekRate', 'Tarif Hebdomadaire ($)')->setCurrency('USD')->setStoredAsCents(true);
        yield MoneyField::new('monthRate', 'Tarif Mensuel ($)')->setCurrency('USD')->setStoredAsCents(true);

        $tenantEm = $this->emProvider->getEntityManager();
        yield AssociationField::new('categories', 'Catégories associées')
            ->setFormTypeOptions([
                'em' => $tenantEm,
            ]);
    }
}
