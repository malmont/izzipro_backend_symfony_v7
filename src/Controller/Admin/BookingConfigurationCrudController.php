<?php
// src/Controller/Admin/BookingConfigurationCrudController.php

namespace App\Controller\Admin;

use App\Entity\BookingConfiguration;
use App\Form\Type\JsonTextType;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

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
                        ->where('p.rentalEnabled = true')
                        ->orderBy('p.name', 'ASC');
                }
            ])
            ->setHelp('Seuls les produits dont la location est activée apparaissent ici.');

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

        // Boutique réglable (08/10/2026) : réglages facultatifs lus par la page produit (bookingConfig)
        yield FormField::addPanel('Boutique réglable (facultatif)')->setHelp('Montants en cents (1 500 $ = 150000). Heures au format HH:MM.');
        yield IntegerField::new('maxDuration', 'Durée maximum')->hideOnIndex()->setColumns('col-md-4');
        yield IntegerField::new('minDaysStandard', 'Jours minimum (à la journée)')->hideOnIndex()->setColumns('col-md-4');
        yield IntegerField::new('arrivalLeadMinutes', 'Arrivée en avance (min)')->hideOnIndex()->setColumns('col-md-4');
        yield TextField::new('openingStart', 'Ouverture (HH:MM)')->hideOnIndex()->setColumns('col-md-3');
        yield TextField::new('openingEnd', 'Fermeture (HH:MM)')->hideOnIndex()->setColumns('col-md-3');
        yield IntegerField::new('deposit', 'Caution (cents)')->hideOnIndex()->setColumns('col-md-3');
        yield IntegerField::new('extraPassengerFee', 'Frais par passager (cents)')->hideOnIndex()->setColumns('col-md-3');
        yield ArrayField::new('allowedDates', 'Dates autorisées (AAAA-MM-JJ)')->hideOnIndex()->setHelp('Vide : toutes les dates.');
        yield ArrayField::new('included', 'Inclus')->hideOnIndex();
        yield ArrayField::new('excluded', 'Non inclus')->hideOnIndex();
        yield Field::new('halfDays', 'Demi-journées (JSON)')->hideOnIndex()->setFormType(JsonTextType::class)
            ->setHelp('[{"label": "Matin", "start": "09:00", "end": "13:00"}, {"label": "Après-midi", "start": "14:00", "end": "18:00"}]');
        yield Field::new('eveningSlot', 'Créneau du soir (JSON)')->hideOnIndex()->setFormType(JsonTextType::class)
            ->setHelp('{"start": "20:30", "end": "23:00"}');
        yield TextareaField::new('cancellationPolicy', 'Politique d\'annulation')->hideOnIndex();
        yield TextareaField::new('notes', 'Notes (essence, nourriture, FAQ…)')->hideOnIndex();
    }
}