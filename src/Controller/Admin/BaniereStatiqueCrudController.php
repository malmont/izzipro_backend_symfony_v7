<?php

namespace App\Controller\Admin;

use App\Entity\BaniereStatique;
use App\Form\BaniereStatiqueTranslationType;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;


class BaniereStatiqueCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return BaniereStatique::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('titre'),
            TextEditorField::new('texte')->hideOnIndex(),
            ImageField::new('imageDeFond', 'Image de fond')
                 ->setBasePath('assets/uploads/slider/')
                ->setUploadDir('public/assets/uploads/slider/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            TextField::new('texteBouton', 'Texte du bouton'),
            ColorField::new('colorBackground', 'Couleur de fond'),
            CollectionField::new('translations', 'Traductions')
                ->setEntryType(BaniereStatiqueTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),    
        ];
    }
}
