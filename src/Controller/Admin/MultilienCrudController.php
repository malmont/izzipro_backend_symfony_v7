<?php

namespace App\Controller\Admin;

use App\Entity\Multilien;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

/**
 * Ce contrôleur hérite de notre base pour être automatiquement "multi-tenant".
 */
class MultilienCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Multilien::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('titre', 'Titre'),
            TextField::new('lien', 'Lien URL'),
            ImageField::new('imageDeFond', 'Image de fond')
                ->setBasePath('assets/uploads/slider/')
                ->setUploadDir('public/assets/uploads/slider/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
        ];
    }
}
