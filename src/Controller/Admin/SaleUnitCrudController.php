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
        yield IdField::new('id', 'ID');
        yield TextField::new('name', "Nom de l'unité (ex: Kilo, Litre)");
    }
}
