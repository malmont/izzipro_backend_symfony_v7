<?php

namespace App\Controller\Admin;

use App\Entity\Fournisseur;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;

class FournisseurCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Fournisseur::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom du Fournisseur'),
            AssociationField::new('typeFournisseur', 'Type de fournisseur'),
            ImageField::new('typeFournisseur.photo', 'Logo note de fournisseur')
            ->setBasePath('/assets/images/')
            ->onlyOnIndex(),  
            TextField::new('adresse', 'Adresse'),
            TextField::new('ville', 'Ville'),
            TextField::new('pays', 'Pays'),
            TextField::new('tel', 'Téléphone'),
        ];
    }
}
