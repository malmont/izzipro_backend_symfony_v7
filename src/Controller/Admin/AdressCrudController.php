<?php
namespace App\Controller\Admin;

use App\Entity\Adress;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;

class AdressCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Adress::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('firstname', 'Prénom'),
            TextField::new('lastname', 'Nom'),
            TextField::new('fullname', 'Nom complet')->hideOnForm(),
            TextField::new('company', 'Entreprise')->hideOnIndex(),
            TextareaField::new('address', 'Adresse'),
            TextareaField::new('complement', 'Complément d\'adresse')->hideOnIndex(),
            TextField::new('phone', 'Téléphone'),
            TextField::new('city', 'Ville'),
            TextField::new('codepostal', 'Code postal'),
            TextField::new('country', 'Pays'),
            AssociationField::new('userAdress', 'Utilisateur associé'),
        ];
    }
}
