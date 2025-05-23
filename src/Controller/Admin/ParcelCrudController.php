<?php

namespace App\Controller\Admin;

use App\Entity\Parcel;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;

class ParcelCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Parcel::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('shippingOrder', 'Shipping Order'),
            IntegerField::new('index', 'Colis #'),
            NumberField::new('weight', 'Poids (kg)'),
            NumberField::new('length', 'Longueur (cm)'),
            NumberField::new('width', 'Largeur (cm)'),
            NumberField::new('height', 'Hauteur (cm)'),
            NumberField::new('price', 'Prix')->onlyOnIndex(),
            AssociationField::new('shippingLabel', 'Étiquette')->hideOnForm(),
        ];
    }
}
