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
            TextField::new('section5Component', 'Type section5'),
            TextField::new('typeComponentSection5', 'typeComponentSection5'),
            TextField::new('section6Component', 'Type section6'),
            TextField::new('typeComponentSection6', 'typeComponentSection6'),
            TextField::new('section7Component', 'Type section7'),
            TextField::new('typeComponentSection7', 'typeComponentSection7'),
            TextField::new('selectTypeProductFetch', ' produit téléchargé section1'),
            TextField::new('selectTypeProductFetchSection2', ' produit téléchargé section2'),
            TextField::new('selectTypeProductFetchSection3', ' produit téléchargé section3'),
            TextField::new('selectTypeProductFetchSection4', 'produit téléchargé section4'),
            TextField::new('selectTypeProductFetchSection5', 'produit téléchargé section5'),
            TextField::new('selectTypeProductFetchSection6', ' produit téléchargé section6'),
            TextField::new('selectTypeProductFetchSection7', 'produit téléchargé section7'),
        ];
    }
}
