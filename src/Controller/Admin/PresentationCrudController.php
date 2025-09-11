<?php

namespace App\Controller\Admin;

use App\Entity\Presentation;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use App\Form\PresentationTranslationType;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;

class PresentationCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Presentation::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('titre'),
            TextEditorField::new('texte')->hideOnIndex(),
            ImageField::new('image', 'Image')
                 ->setBasePath('assets/uploads/slider/')
                ->setUploadDir('public/assets/uploads/slider/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            CollectionField::new('translations', 'Traductions')
                ->setEntryType(PresentationTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),    
            TextField::new('texteBouton', 'Texte du bouton'),
            UrlField::new('lienBouton', 'Lien du bouton'),
        ];
    }
}
