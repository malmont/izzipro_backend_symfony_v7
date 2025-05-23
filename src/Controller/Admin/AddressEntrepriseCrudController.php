<?php

namespace App\Controller\Admin;

use App\Entity\AddressEntreprise;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;

class AddressEntrepriseCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AddressEntreprise::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Adresse Entreprise')
            ->setEntityLabelInPlural('Adresses Entreprises')
            ->setPageTitle(Crud::PAGE_INDEX, 'Adresses Entreprises');
    }

    public function configureFields(string $pageName): iterable
    {
        // Lien vers l'entité Entreprise
        yield AssociationField::new('entreprise', 'Entreprise');

        // Champs d'adresse façon EasyPost
        yield TextField::new('street1', 'Rue N°1');
        yield TextField::new('street2', 'Rue N°2');
        yield TextField::new('city', 'Ville');
        yield TextField::new('state', 'État/Province');
        yield TextField::new('zip', 'Code Postal');
        yield TextField::new('country', 'Pays (ISO)');

        // Coordonnées
        yield TextField::new('phone', 'Téléphone');
        yield TextField::new('email', 'E-mail');

    }
}
