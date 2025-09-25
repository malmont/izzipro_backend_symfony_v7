<?php

namespace App\Controller\Admin;

use App\Entity\ProductOption;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use App\Controller\Admin\BaseTenantCrudController; 
use App\Form\ProductOptionTranslationType;

// On étend la base pour le multi-tenant
class ProductOptionCrudController extends BaseTenantCrudController
{


    public static function getEntityFqcn(): string
    {
        return ProductOption::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom de l\'option'),
            TextField::new('code', 'Code')
                ->setHelp('Identifiant unique pour la logique (ex: "material", "fit"). Ne pas traduire.'),
            CollectionField::new('translations', 'Nom par langue')
                ->setEntryType(ProductOptionTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),
        ];
    }
    
}