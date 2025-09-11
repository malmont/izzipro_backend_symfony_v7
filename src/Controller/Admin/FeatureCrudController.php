<?php

namespace App\Controller\Admin;

use App\Entity\Feature;
use App\Form\FeatureTranslationType;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\{IdField, TextField, ImageField, CollectionField};

class FeatureCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Feature::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('title', 'Titre');
        yield CollectionField::new('translations', 'Traductions')
            ->setEntryType(FeatureTranslationType::class)
            ->setFormTypeOption('by_reference', false)
            ->onlyOnForms();
        yield ImageField::new('iconPath', 'Icône')
            ->setBasePath('assets/uploads/icons')
            ->setUploadDir('public/assets/uploads/icons')
            ->setUploadedFileNamePattern('[slug]-[timestamp].[extension]')
            ->setRequired(false);
    }
}
