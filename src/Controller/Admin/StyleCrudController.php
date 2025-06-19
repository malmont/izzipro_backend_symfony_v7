<?php
namespace App\Controller\Admin;

use App\Entity\Style;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

// 2. On étend notre contrôleur de base
class StyleCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Style::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Style Name'),
        ];
    }
}