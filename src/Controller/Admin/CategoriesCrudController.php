<?php

namespace App\Controller\Admin;

use App\Entity\Categories;
use App\Form\CategoriesTranslationType;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;

class CategoriesCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Categories::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextEditorField::new('description'),
            ImageField::new('image')->setBasePath('assets/uploads/categories/')
                ->setUploadDir('public/assets/uploads/categories/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            CollectionField::new('translations', 'Traductions')
                ->setEntryType(CategoriesTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),
            TextField::new('name', 'Nom (Défaut)'),
        ];
    }
}