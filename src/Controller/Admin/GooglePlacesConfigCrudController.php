<?php

namespace App\Controller\Admin;

use App\Entity\GooglePlacesConfig;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class GooglePlacesConfigCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return GooglePlacesConfig::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('googleApiKeyTest', 'Clé Google Places (test)'),
            TextField::new('googleApiKeyProdEncrypted', 'Clé Google Places (prod)')
                ->setFormTypeOption('mapped', false)
                ->onlyOnForms()
                ->setHelp('Entrez la clé prod en clair, elle sera chiffrée automatiquement'),
        ];
    }
}
