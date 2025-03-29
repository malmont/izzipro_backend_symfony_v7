<?php

namespace App\Controller\Admin;

use App\Entity\NoteDeFrais;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;

class NoteDeFraisCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return NoteDeFrais::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom de la Note de Frais')->onlyOnIndex(),
            TextEditorField::new('description', 'Description'),
            MoneyField::new('montant', 'Montant')->setCurrency('EUR'),
            DateField::new('date', 'Date'),
            AssociationField::new('Collection', 'Collection Associée'), // Utilisation de AssociationField pour le champ 'collection'
            UrlField::new('imageNdf', 'URL de l\'image de la Note de Frais'), // Utilisation de UrlField pour gérer l'URL de l'image
            ImageField::new('imageNdf', 'photoNoteDeFrais')
                ->setBasePath('assets/images/')
                ->setUploadDir('public/assets/images/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),   
        ];
    }
}
