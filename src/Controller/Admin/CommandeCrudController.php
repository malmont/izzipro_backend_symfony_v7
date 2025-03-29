<?php

namespace App\Controller\Admin;

use App\Entity\Commande;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;


class CommandeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Commande::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            MoneyField::new('budget', 'Budget')->setCurrency('USD'),
            DateField::new('date', 'Date'),
            TextField::new('name', 'Nom de la Commande'),
            AssociationField::new('collections', 'Collections'),
            AssociationField::new('fournisseur', 'Fournisseur Associé'),
            BooleanField::new('isClosed', 'isClosed'),
            AssociationField::new('commandepictures', 'Image de la collection'),
            ImageField::new('commandepictures.imageUrl', 'Aperçu de l\'image')
            ->setBasePath('assets/images/')
            ->onlyOnIndex(),
        ];
    }
}
