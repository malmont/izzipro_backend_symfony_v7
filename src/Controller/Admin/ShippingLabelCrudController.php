<?php

namespace App\Controller\Admin;

use App\Entity\ShippingLabel;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;

class ShippingLabelCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ShippingLabel::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('parcel', 'Colis'),
            UrlField::new('labelUrl', 'URL Étiquette')->hideOnForm(),
            TextField::new('trackingCode', 'Tracking Code')->onlyOnIndex(),
            DateTimeField::new('createdAt', 'Créé le')->onlyOnIndex(),
        ];
    }
}
