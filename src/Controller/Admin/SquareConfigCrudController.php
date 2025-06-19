<?php
namespace App\Controller\Admin;

use App\Entity\SquareConfig;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;

// 2. On étend notre contrôleur de base
class SquareConfigCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return SquareConfig::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('accessToken', 'Access Token')->hideOnIndex(),
            TextField::new('applicationId', 'Application ID'),
            TextField::new('locationId', 'Location ID'),
            BooleanField::new('isActive', 'Actif'),
        ];
    }
}