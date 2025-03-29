<?php

namespace App\Controller\Admin;

use App\Entity\FraisDePort;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;

class FraisDePortCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return FraisDePort::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom'),
            TextField::new('facture', 'Facture'),
            TextField::new('tracknumber', 'Numéro de suivi'),
            MoneyField::new('price', 'Prix')->setCurrency('EUR'),
            AssociationField::new('commande', 'Commande')->autocomplete(),
            AssociationField::new('transporteur', 'Transporteur'),
             ImageField::new('transporteur.logo', 'Logo du Transporteur')
            ->setBasePath('/assets/uploads/Carrier/')
            ->onlyOnIndex(),
 
        ];
    }
}
