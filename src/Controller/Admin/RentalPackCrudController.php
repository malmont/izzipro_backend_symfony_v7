<?php

namespace App\Controller\Admin;

use App\Entity\RentalPack;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

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
        yield IntegerField::new('gemsuiteProductId', 'ID Produit Gemsuite');
        
        yield NumberField::new('hourRate', 'Tarif Horaire (€)');
        yield NumberField::new('halfDayRate', 'Tarif Demi-Journée (€)');
        yield NumberField::new('dayRate', 'Tarif Journalier (€)');
        yield NumberField::new('weekRate', 'Tarif Hebdomadaire (€)');
        yield NumberField::new('monthRate', 'Tarif Mensuel (€)');

        $tenantEm = $this->emProvider->getEntityManager();
        yield AssociationField::new('categories', 'Catégories associées')
            ->setFormTypeOptions([
                'em' => $tenantEm,
            ]);
    }
}
