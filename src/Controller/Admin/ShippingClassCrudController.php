<?php
namespace App\Controller\Admin;

use App\Entity\ShippingClass;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;

// 2. On étend notre contrôleur de base
class ShippingClassCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return ShippingClass::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('name', 'Nom')
                ->setRequired(true),
            TextareaField::new('description', 'Description')
                ->setRequired(false),
            // Valeurs héritées par les produits de la classe sans fiche poids (lot 1 bis, 07/11/2026)
            \EasyCorp\Bundle\EasyAdminBundle\Field\NumberField::new('defaultWeightKg', 'Poids par défaut (kg)')->setNumDecimals(3)->setRequired(false)->setHelp('Un produit de cette classe sans fiche poids hérite cette valeur.'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\NumberField::new('defaultLengthCm', 'Longueur par défaut (cm)')->setNumDecimals(1)->setRequired(false)->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\NumberField::new('defaultWidthCm', 'Largeur par défaut (cm)')->setNumDecimals(1)->setRequired(false)->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\NumberField::new('defaultHeightCm', 'Hauteur par défaut (cm)')->setNumDecimals(1)->setRequired(false)->hideOnIndex(),
        ];
    }
}