<?php

namespace App\Controller\Admin;

use App\Entity\SubscriptionPlan;
use App\Controller\Admin\Field\JsonTextField;
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Doctrine\ORM\EntityManagerInterface;

/** Formules d'abonnement de la boutique réglable : le prix Stripe est créé à la première souscription */
class SubscriptionPlanCrudController extends BaseTenantCrudController
{
    public function __construct(TenantEntityManagerProvider $emProvider, private readonly ?\App\Abo\Services\BenefitService $benefits = null,
        private readonly ?\App\Abo\Services\AboCatalogService $catalog = null) // lot ABO-CONF : le service commun (contrôles, historique des prix)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return SubscriptionPlan::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Formule d\'abonnement')->setEntityLabelInPlural('Formules d\'abonnement')
            ->setHelp('index', 'Un produit dont « Abonnement » est coché dans « Modes de vente » se souscrit avec l\'une de ces formules. Montants en dollars ; un prix changé crée un nouveau prix Stripe à la prochaine souscription (les abonnés en cours gardent l\'ancien).');
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('product', 'Produit')->setRequired(true)->setFormTypeOptions([
            'em' => $tenantEm,
            'query_builder' => fn ($repo) => $repo->createQueryBuilder('p')->where('p.subscriptionEnabled = true')->orderBy('p.name', 'ASC'),
        ])->setHelp('Seuls les produits dont l\'abonnement est activé apparaissent.');
        yield JsonTextField::new('names', 'Noms par langue (JSON)')->setHelp('{"fr": "Panier hebdo", "en": "Weekly basket"}')
            ->formatValue(fn ($value) => is_array($value) ? ($value['fr'] ?? reset($value)) : $value);
        yield ChoiceField::new('interval', 'Périodicité')->setChoices(['Semaine' => 'week', 'Mois' => 'month', 'Année' => 'year']);
        yield IntegerField::new('intervalCount', 'Toutes les N périodes')->setHelp('1 = chaque semaine / mois / année ; 2 = toutes les deux…');
        yield MoneyField::new('price', 'Prix par période')->setCurrency('CAD')->setStoredAsCents(true);
        yield TextField::new('currency', 'Devise (ISO)')->hideOnIndex();
        yield IntegerField::new('trialDays', 'Jours d\'essai gratuit')->hideOnIndex();
        yield IntegerField::new('minimumTerms', 'Engagement minimal (périodes)')->hideOnIndex();
        yield BooleanField::new('active', 'Active');
        // Branchement sur un service (console de pilotage, lot 3 bis, 04/11/2026) : réservé au site de la plateforme qui vend ses offres ; une formule ordinaire reste « aucun »
        yield ChoiceField::new('fulfillmentType', 'Branchement sur un service')->setChoices(['Aucun (formule ordinaire)' => SubscriptionPlan::FULFILLMENT_NONE, 'Offre de la plateforme' => SubscriptionPlan::FULFILLMENT_PLATFORM_OFFER, 'Webhook (réservé)' => SubscriptionPlan::FULFILLMENT_WEBHOOK])
            ->hideOnIndex()->setPermission('ROLE_SUPER_ADMIN')->setHelp('« Offre de la plateforme » : la formule active une offre (code ci-dessous) sur le site que l\'abonné désigne à la souscription ; les impayés et résiliations suspendent ou retirent le service. Seul le site désigné comme boutique de la plateforme produit des droits.');
        yield TextField::new('fulfillmentRef', 'Code de l\'offre ou URL du webhook')->hideOnIndex()->setPermission('ROLE_SUPER_ADMIN')->setHelp('Codes des offres : voir la console Plateforme (ex. landing_boutique, pos_management, all).');
        yield BooleanField::new('highlighted', 'Recommandée (mise en avant)')->setHelp('Une seule par produit : cocher une formule décoche les autres du même produit.');
        yield JsonTextField::new('descriptions', 'Sous-titre par langue (JSON)')->hideOnIndex()
            ->setHelp('{"fr": "Solution discount", "en": "Budget plan"} — texte en clair, 160 caractères au plus.');
        yield JsonTextField::new('features', 'Avantages par langue (JSON)')->hideOnIndex()
            ->setHelp('{"fr": ["Livraison offerte", "Sans engagement"], "en": ["Free delivery", "No commitment"]} — 20 avantages au plus, 120 caractères chacun, texte en clair.');
        yield JsonTextField::new('badges', 'Texte du badge par langue (JSON)')->hideOnIndex()
            ->setHelp('Facultatif, pour la formule recommandée : {"fr": "Le plus choisi", "en": "Most popular"}. Sans texte, le site affiche « Recommandé ».');
        yield TextField::new('stripePriceId', 'Prix Stripe')->onlyOnDetail();
        // lot ABO-0 : ce que la formule livre, avec la phrase « ce que verra l'abonné » (réglage dans « Prestations des formules »)
        yield TextField::new('id', 'Prestations livrées')->onlyOnDetail()->formatValue(fn ($value, ?SubscriptionPlan $plan) => $plan !== null && $this->benefits !== null
            ? (implode(' ', array_map(fn (array $b) => '• ' . $b['summary'], $this->benefits->viewsFor($plan, 'fr'))) ?: 'Aucune prestation : la formule livre son produit.') : '');
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->normalize($entityInstance);
        if (!$this->valid($entityInstance)) {
            return;
        }
        parent::persistEntity($entityManager, $entityInstance);
        $this->catalog?->afterSave($entityInstance, null, $this->getUser()?->getUserIdentifier());
        $entityManager->flush();
        $this->emProvider->getEntityManager()->getRepository(SubscriptionPlan::class)->keepOnlyHighlighted($entityInstance);
        foreach ($this->catalog?->warnings() ?? [] as $warning) {
            $this->addFlash('warning', $warning['text']); // décision du propriétaire : prévenir qu'un compte Stripe est nécessaire pour vendre en réel
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->normalize($entityInstance);
        if (!$this->valid($entityInstance)) {
            return;
        }
        // lot ABO-CONF : prix ou rythme modifiés = un nouveau prix Stripe pour les NOUVEAUX abonnés (l'ancien restait, même pour eux)
        $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
        $before = ['price' => (int) ($original['price'] ?? $entityInstance->getPrice()), 'currency' => (string) ($original['currency'] ?? $entityInstance->getCurrency()),
            'interval' => (string) ($original['interval'] ?? $entityInstance->getInterval()), 'intervalCount' => (int) ($original['intervalCount'] ?? $entityInstance->getIntervalCount()),
            'stripePriceId' => $original['stripePriceId'] ?? $entityInstance->getStripePriceId()];
        $this->catalog?->afterSave($entityInstance, $before, $this->getUser()?->getUserIdentifier());
        parent::updateEntity($entityManager, $entityInstance);
        $this->emProvider->getEntityManager()->getRepository(SubscriptionPlan::class)->keepOnlyHighlighted($entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        try {
            $this->catalog?->assertDeletable($entityInstance); // lot ABO-CONF : une formule qui a eu des abonnés s'archive
        } catch (\App\Abo\Services\AboException $e) {
            $this->addFlash('danger', $e->getMessage());

            return;
        }
        parent::deleteEntity($entityManager, $entityInstance);
    }

    /** Les contrôles du service commun (lot ABO-CONF) ; un refus devient un message de l'écran */
    private function valid(SubscriptionPlan $plan): bool
    {
        try {
            $this->catalog?->validatePlan($plan);

            return true;
        } catch (\App\Abo\Services\AboException $e) {
            $this->addFlash('danger', $e->getMessage());

            return false;
        }
    }

    /** Textes saisis dans le formulaire nettoyés comme par l'API (en clair, longueurs bornées) */
    private function normalize(SubscriptionPlan $plan): void
    {
        $plan->setFeatures($plan->getFeatures())->setDescriptions($plan->getDescriptions())->setBadges($plan->getBadges());
    }
}
