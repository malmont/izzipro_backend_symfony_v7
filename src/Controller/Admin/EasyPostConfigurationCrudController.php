<?php

namespace App\Controller\Admin;

use App\Entity\EasyPostConfiguration;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class EasyPostConfigurationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return EasyPostConfiguration::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            // Affiche l’ID dans la liste, mais pas dans le formulaire
            IdField::new('id')->hideOnForm(),

            // Clé sandbox visible partout
            TextField::new('easypostApiKeySandbox', 'Sandbox API Key')
                ->setHelp('Clé utilisée pour l\'environnement de test'),

            // Clé production masquée hors formulaire
            TextField::new('easypostApiKeyProd', 'Production API Key')
                ->onlyOnForms()
                ->setHelp('Clé chiffrée, masquée sur l’index et la vue détail'),
        ];
    }
}
