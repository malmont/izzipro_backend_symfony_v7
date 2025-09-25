<?php

namespace App\Controller\Admin;

use App\Entity\Recherche;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use App\Form\RechercheTranslationType;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;

/**
 * Ce contrôleur hérite de notre base pour être automatiquement "multi-tenant".
 */
class RechercheCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Recherche::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('titre', 'Titre'),
            TextareaField::new('texte1', 'Texte 1')->hideOnIndex(),
            TextareaField::new('texte2', 'Texte 2')->hideOnIndex(),
            CollectionField::new('translations', 'Traductions')
                ->setEntryType(RechercheTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),
            ImageField::new('imageDeFond', 'Image de fond')
                ->setBasePath('assets/uploads/slider/')
                ->setUploadDir('public/assets/uploads/slider/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
        ];
    }
}
