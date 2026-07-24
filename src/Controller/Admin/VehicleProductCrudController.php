<?php

namespace App\Controller\Admin;

use App\Entity\VehicleProduct;
use App\Enum\ProductMode;
use App\Repository\CategoriesRepository;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class VehicleProductCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return VehicleProduct::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Véhicule / Bateau à Vendre')
            ->setEntityLabelInPlural('Véhicules & Bateaux (Vente)')
            ->setPageTitle(Crud::PAGE_INDEX, 'Gestion des Véhicules & Embarcations à Vendre');
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        yield FormField::addTab('Caractéristiques du Véhicule');
        yield FormField::addPanel('Informations Générales');

        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Nom / Titre du Véhicule');
        yield SlugField::new('slug', 'Slug')->setTargetFieldName('name')->hideOnIndex();
        
        yield TextField::new('brand', 'Marque')->setColumns('col-md-4');
        yield TextField::new('model', 'Modèle')->setColumns('col-md-4');
        yield IntegerField::new('year', 'Année (ex: 2026)')->setColumns('col-md-4');

        yield FormField::addPanel('Spécifications Techniques');
        yield TextField::new('vin', 'N° de Série / VIN')->setColumns('col-md-4');
        yield ChoiceField::new('transmission', 'Transmission')
            ->setChoices([
                'Automatique' => 'Automatique',
                'Manuelle' => 'Manuelle',
                'Hors-Bord (Outboard)' => 'Outboard',
                'Inboard' => 'Inboard',
                'Direct Drive' => 'Direct Drive',
            ])
            ->setColumns('col-md-4');

        yield ChoiceField::new('gasType', 'Carburant')
            ->setChoices([
                'Essence' => 'Essence',
                'Diesel' => 'Diesel',
                'Électrique' => 'Électrique',
                'Hybride' => 'Hybride',
            ])
            ->setColumns('col-md-4');

        yield TextField::new('enginePower', 'Puissance Moteur (ex: 250 HP)')->setColumns('col-md-4');
        yield IntegerField::new('hoursOrMileage', 'Heures de nav. / Kilométrage')->setColumns('col-md-4');
        yield ChoiceField::new('vehicleCondition', 'État du Véhicule')
            ->setChoices([
                'Neuf' => 'neuf',
                'Occasion' => 'occasion',
            ])
            ->setColumns('col-md-4');

        yield FormField::addTab('Prix & Mode de Commercialisation');
        yield FormField::addPanel('Tarification & Stock');

        yield NumberField::new('price', 'Prix de Vente / Tarif de Base ($)')
            ->setHelp('Prix en dollars. Ex: 45000.00 $');

        yield IntegerField::new('quantity', 'Quantité en Stock (Ex: 1)');

        yield ChoiceField::new('mode', 'Mode de Commercialisation')
            ->setChoices([
                'Vente Sèche (Achat Direct)' => ProductMode::RETAIL,
                'Location / Réservation' => ProductMode::BOOKING,
            ])
            ->renderAsBadges([
                ProductMode::RETAIL->value => 'success',
                ProductMode::BOOKING->value => 'warning',
            ])
            ->setColumns('col-md-6');

        yield AssociationField::new('bookingConfiguration', 'Configuration de Location (Si Loué)')
            ->setFormTypeOptions(['em' => $tenantEm])
            ->setHelp('Associez un pool de stock et des règles de créneaux si ce véhicule est proposé à la location.')
            ->setColumns('col-md-6');

        yield AssociationField::new('category', 'Catégorie')
            ->setFormTypeOptions([
                'em' => $tenantEm,
                'query_builder' => function (CategoriesRepository $repo) {
                    return $repo->createQueryBuilder('c')->orderBy('c.name', 'ASC');
                },
                'choice_label' => 'name',
            ]);

        yield ImageField::new('image', 'Image Principale')
            ->setBasePath('/assets/uploads/products/')
            ->setUploadDir('public/assets/uploads/products/')
            ->setUploadedFileNamePattern('[slug]-[timestamp].[extension]')
            ->setRequired(false);

        yield TextEditorField::new('description', 'Description Complète')->hideOnIndex();
    }
}
