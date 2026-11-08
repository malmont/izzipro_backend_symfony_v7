<?php
// src/Controller/Admin/BookingConfigurationCrudController.php

namespace App\Controller\Admin;

use App\Controller\Admin\Field\JsonTextField;
use App\Entity\BookingConfiguration;
use App\Services\Booking\RentalSummaryService;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Règles de réservation d'un produit en location (« Règles & Stocks »). Réécrit le 08/11/2026 : panneaux explicites (grille, durées,
 * horaires, dates, caution), unités dites en clair, montants dans la devise du site, encadré « Ce que verra le client »
 * (RentalSummaryService) sur la fiche et la modification, action « Configurer pas à pas » vers le questionnaire.
 */
class BookingConfigurationCrudController extends BaseTenantCrudController
{
    public function __construct(TenantEntityManagerProvider $emProvider, private readonly ?RentalSummaryService $summary = null, private readonly ?TenantCurrencyProvider $currency = null)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return BookingConfiguration::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Règles de location')->setEntityLabelInPlural('Règles de location (par produit)')
            ->setPageTitle(Crud::PAGE_INDEX, 'Règles de location par produit')->setPageTitle(Crud::PAGE_NEW, 'Nouvelles règles de location')->setPageTitle(Crud::PAGE_EDIT, fn (BookingConfiguration $c) => 'Règles de location : ' . ($c->getProduct()?->getName() ?? ''))
            ->setPageTitle(Crud::PAGE_DETAIL, fn (BookingConfiguration $c) => 'Règles de location : ' . ($c->getProduct()?->getName() ?? ''))
            ->setHelp(Crud::PAGE_INDEX, 'Une ligne par produit en location : comment le client réserve, combien de temps, à quelles heures, pour quel prix (les prix sont dans « Grilles tarifaires »). Le plus simple : « Configurer pas à pas ».');
    }

    public function configureActions(Actions $actions): Actions
    {
        $wizard = Action::new('rentalWizard', 'Configurer pas à pas', 'fas fa-list-ol')
            ->linkToUrl(fn (BookingConfiguration $c) => $this->container->get(AdminUrlGenerator::class)->unsetAll()->setRoute('admin_location_configurer', ['product' => $c->getProduct()?->getId()])->generateUrl());
        $wizardIndex = Action::new('rentalWizardIndex', 'Configurer un produit pas à pas', 'fas fa-list-ol')->createAsGlobalAction()
            ->linkToUrl(fn () => $this->container->get(AdminUrlGenerator::class)->unsetAll()->setRoute('admin_location_configurer')->generateUrl());

        return parent::configureActions($actions)->add(Crud::PAGE_INDEX, $wizard)->add(Crud::PAGE_INDEX, $wizardIndex)->add(Crud::PAGE_EDIT, $wizard)->add(Crud::PAGE_DETAIL, $wizard)->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        $currency = $this->currency?->code() ?? 'CAD';
        $tenantEm = $this->emProvider->getEntityManager();
        $instance = in_array($pageName, [Crud::PAGE_EDIT, Crud::PAGE_DETAIL], true) ? $this->getContext()?->getEntity()?->getInstance() : null;

        yield IdField::new('id')->hideOnForm();
        if ($instance instanceof BookingConfiguration && $this->summary !== null) {
            yield FormField::addPanel('Ce que verra le client')->setIcon('fas fa-eye')->setHelp($this->summary->html($instance));
        } elseif ($pageName === Crud::PAGE_NEW) {
            yield FormField::addPanel('Ce que verra le client')->setIcon('fas fa-eye')->setHelp('Enregistrez : le résumé de ce que le client verra et les incohérences éventuelles s\'affichent ici. Plus simple encore : l\'action « Configurer pas à pas » pose des questions en langage courant.');
        }

        yield FormField::addPanel('Produit et façon de réserver')->setIcon('fas fa-calendar-check')->collapsible();
        yield AssociationField::new('product', 'Produit')
            ->setRequired(true)
            ->setFormTypeOptions(['em' => $tenantEm, 'query_builder' => fn ($repo) => $repo->createQueryBuilder('p')->where('p.rentalEnabled = true')->orderBy('p.name', 'ASC')])
            ->setHelp('Seuls les produits dont la case « Location » est cochée sur la fiche apparaissent ici.');
        yield ChoiceField::new('granularity', 'Comment le client réserve')
            ->setChoices(['À la journée : une date de début et une date de fin, durées comptées en jours' => 'days', 'Avec date et heure de prise et de retour, sur un ou plusieurs jours (durées en heures, prix par tranches de 24 h arrondies au-dessus)' => 'datetime', 'À l\'heure : un créneau de début et de fin dans la journée, durées comptées en heures' => 'hours', 'Par tranches de 30 minutes (durées en heures)' => 'minutes_30', 'Par tranches de 15 minutes (durées en heures)' => 'minutes_15'])
            ->setHelp('Décide du calendrier affiché au client et de l\'unité des durées ci-dessous.')
            ->formatValue(fn ($v) => RentalSummaryService::GRIDS[$v] ?? $v);

        yield FormField::addPanel('Durées autorisées')->setIcon('fas fa-hourglass-half')->collapsible();
        yield IntegerField::new('minDuration', 'Durée minimale')->setHelp('En heures pour une grille horaire, en jours à la journée. Ex. : 1 → au moins 1 h (ou 1 jour).')->setColumns('col-md-4');
        yield IntegerField::new('maxDuration', 'Durée maximale')->setHelp('Même unité. Vide : sans limite. Ex. : grille à l\'heure, 8 → au plus 8 h d\'affilée ; les formules « journée », « semaine », « mois » des grilles tarifaires deviennent alors hors d\'atteinte.')->setColumns('col-md-4')->hideOnIndex();
        yield IntegerField::new('returnToleranceMinutes', 'Retard toléré au retour (minutes, prise et retour)')->setHelp('Grille « date et heure » seulement. Ex. : 60 → une heure de plus au retour ne coûte pas une journée de plus.')->setColumns('col-md-4')->hideOnIndex();
        yield IntegerField::new('timeStepMinutes', 'Pas des heures (minutes, prise et retour)')->setHelp('Heures proposées au client : 60 = heures pleines, 30 = demi-heures. Vide : 60.')->setColumns('col-md-4')->hideOnIndex();
        yield IntegerField::new('minDaysStandard', 'Jours minimum facturés (grille à la journée)')->setHelp('Facultatif. Ex. : 2 → un séjour d\'une nuit est refusé.')->setColumns('col-md-4')->hideOnIndex();

        yield FormField::addPanel('Exemplaires, horaires et dates')->setIcon('fas fa-clock')->collapsible();
        yield IntegerField::new('stockQuantity', 'Exemplaires identiques')->setHelp('Combien de clients peuvent louer en même temps (ex. : 3 motomarines → 3).')->setColumns('col-md-4');
        yield IntegerField::new('bufferTime', 'Battement entre deux locations (minutes)')->setHelp('Temps de préparation : le créneau suivant commence après ce délai.')->setColumns('col-md-4')->hideOnIndex();
        yield IntegerField::new('arrivalLeadMinutes', 'Arrivée en avance (minutes)')->setHelp('Rappelé au client ; n\'influe pas sur les créneaux.')->setColumns('col-md-4')->hideOnIndex();
        yield TextField::new('openingStart', 'Ouverture (HH:MM)')->setHelp('Grilles horaires : une location commence et finit le même jour. Grille « date et heure » : la prise et le retour tombent dans ces heures, n\'importe quel jour.')->setColumns('col-md-3')->hideOnIndex();
        yield TextField::new('openingEnd', 'Fermeture (HH:MM)')->setColumns('col-md-3')->hideOnIndex();
        yield JsonTextField::new('eveningSlot', 'Créneau du soir (facultatif)')->hideOnIndex()->setColumns('col-md-6')
            ->setHelp('Second créneau après la fermeture, par exemple {"start": "20:30", "end": "23:00"}.');
        yield JsonTextField::new('halfDays', 'Demi-journées proposées (facultatif)')->hideOnIndex()
            ->setHelp('Choix d\'un clic au tarif demi-journée : [{"label": "Matin", "start": "09:00", "end": "13:00"}, {"label": "Après-midi", "start": "14:00", "end": "18:00"}]');
        yield ArrayField::new('allowedDates', 'Dates ouvertes (AAAA-MM-JJ)')->hideOnIndex()->setHelp('Vide : toutes les dates. Sinon, seules ces dates sont proposées (événement, saison courte).');

        yield FormField::addPanel('Caution et frais')->setIcon('fas fa-coins')->collapsible()->setHelp('Les prix de location eux-mêmes (heure, demi-journée, journée, semaine, mois) se règlent dans « Grilles tarifaires », rattachées aux catégories du produit.');
        yield MoneyField::new('deposit', 'Caution')->setCurrency($currency)->setStoredAsCents(true)->setHelp('Affichée au client, non comprise dans le total payé en ligne.')->setColumns('col-md-4')->hideOnIndex();
        yield MoneyField::new('extraPassengerFee', 'Frais par passager supplémentaire')->setCurrency($currency)->setStoredAsCents(true)->setColumns('col-md-4')->hideOnIndex();

        yield FormField::addPanel('Textes affichés au client')->setIcon('fas fa-align-left')->collapsible();
        yield ArrayField::new('included', 'Inclus')->hideOnIndex()->setHelp('Un élément par ligne : gilets, formation…');
        yield ArrayField::new('excluded', 'Non inclus')->hideOnIndex()->setHelp('Essence, nourriture…');
        yield TextareaField::new('cancellationPolicy', 'Politique d\'annulation')->hideOnIndex();
        yield TextareaField::new('notes', 'Notes (FAQ, consignes)')->hideOnIndex();
    }
}
