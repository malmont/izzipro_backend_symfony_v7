<?php
// src/Controller/Admin/BookingConfigurationCrudController.php

namespace App\Controller\Admin;

use App\Entity\BookingConfiguration;
use App\Entity\Product;
use App\Enum\ProductMode; 
use App\Repository\ProductRepository;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;

class BookingConfigurationCrudController extends BaseTenantCrudController
{
    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return BookingConfiguration::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        $tenantEm = $this->emProvider->getEntityManager();

        yield AssociationField::new('product', 'Produit concerné')
            ->setRequired(true)
            ->setFormTypeOptions([
                'em' => $tenantEm, 
                'query_builder' => function ($repo) {
                    return $repo->createQueryBuilder('p')
                        ->where('p.mode = :mode')
                        ->setParameter('mode', ProductMode::BOOKING)
                        ->orderBy('p.name', 'ASC');
                }
            ])
            ->setHelp('Seuls les produits configurés en mode "Location" apparaissent ici.');

        yield ChoiceField::new('granularity', 'Unité de temps (Grille)')
            ->setChoices([
                'À la journée / Nuitée' => 'days',
                'À l\'heure' => 'hours',
                'À la demi-heure (30 min)' => 'minutes_30', 
                'Au quart d\'heure (15 min)' => 'minutes_15', 
            ]);

        yield IntegerField::new('stockQuantity', 'Stock du Pool')
            ->setHelp('Combien d\'objets identiques avez-vous ? (Ex: 20 Kayaks)');

        yield IntegerField::new('minDuration', 'Durée Minimum')
            ->setHelp('En heures ou en jours selon l\'unité choisie.');

        yield IntegerField::new('bufferTime', 'Temps de battement (min)')
            ->setHelp('Temps de préparation entre deux clients (en minutes).')
            ->hideOnIndex();
    }
}