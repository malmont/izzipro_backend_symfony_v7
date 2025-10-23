<?php
// src/Controller/Admin/PackagingTypeCrudController.php

namespace App\Controller\Admin;

use App\Entity\PackagingType;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;

class PackagingTypeCrudController extends BaseTenantCrudController
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
                ->setRequired(true)
                ->setColumns(6),

            IntegerField::new('volumetricDivisor', 'Diviseur volumétrique')
                ->setHelp('Ex : 5000 ou 6000 (laisser 0 si non applicable)')
                ->setRequired(false)
                ->setColumns(6),
            
            FormField::addPanel('Dimensions Internes (pour le "bin packing")')
                ->setIcon('fa fa-box-open')
                ->setHelp('Dimensions à l\'intérieur du carton.'),

            // --- PROPRIÉTÉS RENOMMÉES ---
            NumberField::new('innerLengthCm', 'Longueur int. (cm)')
                ->setNumDecimals(1)
                ->setRequired(true)
                ->setColumns(4),
            NumberField::new('innerWidthCm', 'Largeur int. (cm)')
                ->setNumDecimals(1)
                ->setRequired(true)
                ->setColumns(4),
            NumberField::new('innerHeight', 'Hauteur int. (cm)') // Pas de conflit
                ->setNumDecimals(1)
                ->setRequired(true)
                ->setColumns(4),

            FormField::addPanel('Dimensions Extérieures (pour le transporteur)')
                ->setIcon('fa fa-ruler-combined')
                ->setHelp('Dimensions extérieures et poids total (carton + contenu).'),

            // --- PROPRIÉTÉS RENOMMÉES ---
            NumberField::new('outerLengthCm', 'Longueur ext. (cm)')
                ->setNumDecimals(1)
                ->setRequired(true)
                ->setColumns(4),
            NumberField::new('outerWidthCm', 'Largeur ext. (cm)')
                ->setNumDecimals(1)
                ->setRequired(true)
                ->setColumns(4),
            NumberField::new('outerHeight', 'Hauteur ext. (cm)') // Pas de conflit
                ->setNumDecimals(1)
                ->setRequired(true)
                ->setColumns(4),

            NumberField::new('emptyWeightKg', 'Poids du carton vide (kg)')
                ->setNumDecimals(2)
                ->setRequired(true)
                ->setColumns(6)
                ->setHelp('Le poids du carton seul, sans produits.'),
            
            NumberField::new('maxWeightKg', 'Poids max total (kg)')
                ->setNumDecimals(2)
                ->setRequired(true)
                ->setColumns(6)
                ->setHelp('Poids total maximum que le carton peut supporter (contenu + carton).'),
        ];
    }
}

