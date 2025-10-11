<?php

namespace App\Controller\Admin;

use App\Entity\GooglePlacesConfig;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class GooglePlacesConfigCrudController extends BaseTenantCrudController
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
            TextField::new('googleApiKeyProd', 'Clé Google Places (prod)')
                ->setFormTypeOption('required', false)
                ->onlyOnForms()
                ->setHelp('Entrez une nouvelle clé pour la mettre à jour. Laissez vide pour ne pas la changer.'),
        ];
    }
}