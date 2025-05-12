<?php
// src/Controller/Admin/PackagingTypeCrudController.php

namespace App\Controller\Admin;

use App\Entity\PackagingType;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

class PackagingTypeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PackagingType::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),

            TextField::new('name', 'Nom du gabarit')
                ->setRequired(true),

            NumberField::new('innerLength', 'Longueur int. (cm)')
                ->setNumDecimals(1)
                ->setRequired(true),
            NumberField::new('innerWidth', 'Largeur int. (cm)')
                ->setNumDecimals(1)
                ->setRequired(true),
            NumberField::new('innerHeight', 'Hauteur int. (cm)')
                ->setNumDecimals(1)
                ->setRequired(true),

            NumberField::new('maxWeight', 'Poids max (kg)')
                ->setNumDecimals(2)
                ->setRequired(true),

            IntegerField::new('volumetricDivisor', 'Diviseur volumétrique')
                ->setHelp('Ex : 5000 ou 6000'),
        ];
    }
}
