<?php

namespace App\Controller\Admin;

use App\Entity\TypeNoteDeFrais;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;

// On étend notre contrôleur de base !
class TypeNoteDeFraisCrudController extends BaseTenantCrudController
{
    // Le constructeur est hérité du parent.
    // Toutes les méthodes CRUD (liste, création, mise à jour, suppression)
    // sont héritées du parent.
    //
    // Il ne reste que la configuration spécifique à CETTE entité.

    public static function getEntityFqcn(): string
    {
        return TypeNoteDeFrais::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('name', 'Nom du Type'),
            ImageField::new('image', 'Image')
                ->setBasePath('assets/images/')
                ->setUploadDir('public/assets/images/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
        ];
    }
}