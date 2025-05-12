<?php
// src/Controller/Admin/ProductShippingCrudController.php

namespace App\Controller\Admin;

use App\Entity\ProductShipping;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ProductShippingCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ProductShipping::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),

            AssociationField::new('product', 'Produit')
                ->setRequired(true),

            NumberField::new('weight', 'Poids (kg)')
                ->setNumDecimals(2)
                ->setRequired(true),

            NumberField::new('length', 'Longueur (cm)')
                ->setNumDecimals(1)
                ->hideOnIndex(),

            NumberField::new('width', 'Largeur (cm)')
                ->setNumDecimals(1)
                ->hideOnIndex(),

            NumberField::new('height', 'Hauteur (cm)')
                ->setNumDecimals(1)
                ->hideOnIndex(),

            AssociationField::new('shippingClassEntity', 'Classe d’expédition')
                ->setRequired(false)
                ->setHelp('Laisse vide pour l’instant si non configuré'),
        ];
    }
}
