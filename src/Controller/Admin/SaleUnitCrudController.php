<?php

namespace App\Controller\Admin;

use App\Entity\SaleUnit;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class SaleUnitCrudController extends BaseTenantCrudController
{
    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return SaleUnit::class;
    }

    public function configureFields(string $pageName): iterable
    {
        // On affiche l'ID car il correspond aux IDs Gemsuite (1 à 9)
        yield IdField::new('id', 'ID Gemsuite');
        yield TextField::new('name', "Nom de l'unité (ex: Kilo, Litre)");
    }
}
