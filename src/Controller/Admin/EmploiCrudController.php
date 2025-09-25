<?php

namespace App\Controller\Admin;

use App\Entity\Emploi;
use App\Form\EmploiTranslationType; 
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class EmploiCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Emploi::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('candidatures', 'Candidatures')->onlyOnIndex(),
            CollectionField::new('translations', 'Traductions')
                ->setEntryType(EmploiTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),
            TextField::new('titre', 'Titre (Défaut)'),
            TextareaField::new('description', 'Description')->hideOnIndex(),
        ];
    }
}