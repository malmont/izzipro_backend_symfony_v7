<?php

namespace App\Controller\Admin;

use App\Entity\SubscriptionPlan;
use App\Form\Type\JsonTextType;
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

/** Formules d'abonnement de la boutique réglable : le prix Stripe est créé à la première souscription */
class SubscriptionPlanCrudController extends BaseTenantCrudController
{
    public function __construct(TenantEntityManagerProvider $emProvider)
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
        yield Field::new('names', 'Noms par langue (JSON)')->setFormType(JsonTextType::class)->setHelp('{"fr": "Panier hebdo", "en": "Weekly basket"}')
            ->formatValue(fn ($value) => is_array($value) ? ($value['fr'] ?? reset($value)) : $value);
        yield ChoiceField::new('interval', 'Périodicité')->setChoices(['Semaine' => 'week', 'Mois' => 'month', 'Année' => 'year']);
        yield IntegerField::new('intervalCount', 'Toutes les N périodes')->setHelp('1 = chaque semaine / mois / année ; 2 = toutes les deux…');
        yield MoneyField::new('price', 'Prix par période')->setCurrency('CAD')->setStoredAsCents(true);
        yield TextField::new('currency', 'Devise (ISO)')->hideOnIndex();
        yield IntegerField::new('trialDays', 'Jours d\'essai gratuit')->hideOnIndex();
        yield IntegerField::new('minimumTerms', 'Engagement minimal (périodes)')->hideOnIndex();
        yield BooleanField::new('active', 'Active');
        yield TextField::new('stripePriceId', 'Prix Stripe')->onlyOnDetail();
    }
}
