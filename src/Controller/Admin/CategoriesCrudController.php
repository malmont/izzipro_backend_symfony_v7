<?php

namespace App\Controller\Admin;

use App\Entity\Categories;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use App\Controller\Admin\BaseTenantCrudController;

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
            TextField::new('name'),    
            TextEditorField::new('description'),
            ImageField::new('image')->setBasePath('assets/uploads/categories/')
                                    ->setUploadDir('public/assets/uploads/categories/')
                                    ->setUploadedFileNamePattern('[randomhash].[extension]')
                                    ->setRequired(false)
        
        ];
    }
    
}
