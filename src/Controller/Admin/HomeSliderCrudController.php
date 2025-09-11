<?php

namespace App\Controller\Admin;

use App\Entity\HomeSlider;
use phpDocumentor\Reflection\Types\Boolean;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use App\Controller\Admin\BaseTenantCrudController;  
use App\Form\HomeSliderTranslationType; 
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;


class HomeSliderCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return HomeSlider::class;
    }

   
    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('title'),
            TextField::new('description'),
            TextField::new('buttonMessage'),
            TextField::new('buttonUrl'),
            CollectionField::new('translations', 'Traductions')
                ->setEntryType(HomeSliderTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),
            ImageField::new('image')->setBasePath('assets/uploads/slider/')
                                    ->setUploadDir('public/assets/uploads/slider/')
                                    ->setUploadedFileNamePattern('[randomhash].[extension]')
                                    ->setRequired(false),
            BooleanField::new('isDiplayed', 'Display'),                        
        ];
    }
 
}
