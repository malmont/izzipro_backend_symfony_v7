<?php

namespace App\Controller\Admin;

use App\Entity\AdminSettings;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class AdminSettingsCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AdminSettings::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('navbarComponent', 'Navbar Component'),
            TextField::new('styleChoice', 'Style Choice'),
            TextField::new('themeChoice', 'Theme Choice'),
            TextField::new('section1Component', 'typesection1'),
            TextField::new('typeComponentSection1', 'typeSection1Component'),
        ];
    }
}
