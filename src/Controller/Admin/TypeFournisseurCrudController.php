<?php

namespace App\Controller\Admin;

use App\Entity\TypeFournisseur;
use App\Controller\Admin\BaseTenantCrudController; // <-- On importe notre base
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;

// On étend notre contrôleur de base !
class TypeFournisseurCrudController extends BaseTenantCrudController
{
    // Le constructeur est hérité du parent.
    // Toutes les méthodes CRUD sont héritées du parent.
    // Il ne reste que la configuration spécifique à CETTE entité.

    public static function getEntityFqcn(): string
    {
        return TypeFournisseur::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('name', 'Nom du Type'),
            ImageField::new('photo', 'Image')
                ->setBasePath('assets/images/')
                ->setUploadDir('public/assets/images/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
        ];
    }
}