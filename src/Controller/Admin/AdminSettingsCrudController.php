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
            TextField::new('section2Component', 'Type section2'),
            TextField::new('typeComponentSection2', 'typeComponentSection2'),
            TextField::new('section3Component', 'Type section3'),
            TextField::new('typeComponentSection3', ' typeComponentSection3'),
            TextField::new('section4Component', 'Type section4'),
            TextField::new('typeComponentSection4', 'typeComponentSection4'),
            TextField::new('selectTypeProductFetch', 'Selection type produit téléchargé section1'),
            TextField::new('selectTypeProductFetchSection2', 'Selection type produit téléchargé section2'),
            TextField::new('selectTypeProductFetchSection3', 'Selection type produit téléchargé section3'),
            TextField::new('selectTypeProductFetchSection4', 'Selection type produit téléchargé section4'),
        ];
    }
}
