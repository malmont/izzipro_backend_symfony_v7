<?php
namespace App\Controller\Admin;

use App\Entity\Size;
use App\Controller\Admin\BaseTenantCrudController; 
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

// 2. On étend notre contrôleur de base
class SizeCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Size::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Size Name'),
        ];
    }
}