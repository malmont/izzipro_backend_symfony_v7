<?php

namespace App\Controller\Admin;

use App\Entity\Banniere;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;

/**
 * Ce contrôleur hérite de notre base pour être automatiquement "multi-tenant".
 */
class BanniereCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Banniere::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('titre', 'Titre'),
            TextEditorField::new('texte', 'Texte')->hideOnIndex(),
            ImageField::new('imageDeFond', 'Image de fond')
               ->setBasePath('assets/uploads/slider/')
                        ->setUploadDir('public/assets/uploads/slider/')
                        ->setUploadedFileNamePattern('[randomhash].[extension]')
                        ->setRequired(false),
        ];
    }
}
