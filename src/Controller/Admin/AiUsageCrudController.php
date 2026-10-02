<?php

namespace App\Controller\Admin;

use App\Entity\AiUsage;
use App\Services\LandingAiService\LandingAiPricing;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Historique de l'assistant IA des landing pages du tenant, en lecture seule (l'historique ne se modifie pas).
 * Le total du mois est rappelé en tête de liste. Le coût estimé en dollars (par demande et total du mois) n'est
 * montré qu'au super administrateur : les administrateurs d'un site voient leurs crédits, pas le coût de revient.
 */
class AiUsageCrudController extends BaseTenantCrudController
{
    private const STATUSES = [
        'Réussie' => AiUsage::STATUS_SUCCESS,
        'Échouée' => AiUsage::STATUS_FAILED,
        'Expirée' => AiUsage::STATUS_EXPIRED,
        'En cours' => AiUsage::STATUS_RESERVED,
    ];

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        private readonly LandingAiQuotaService $quota,
        private readonly LandingAiPricing $pricing
    ) {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return AiUsage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $credits = $this->quota->credits();
        $cost = '';
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            $month = $this->quota->monthUsages();
            $cost = sprintf(
                ' Coût estimé du mois sur ce site : %s $ US pour %d demande(s) ayant appelé l\'IA, échecs compris (estimation d\'après les jetons ; la console Anthropic fait foi).',
                number_format($this->pricing->total($month), 2, ',', ' '),
                count($month)
            );
        }

        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Demande à l\'assistant IA')
            ->setEntityLabelInPlural('Assistant IA : historique')
            ->setPageTitle(Crud::PAGE_INDEX, 'Assistant IA : historique des demandes')
            ->setHelp(Crud::PAGE_INDEX, sprintf(
                'Crédits du mois : %d utilisé(s) sur %d, %d restant(s) (renouvellement le %s). Les demandes échouées ou expirées ne consomment aucun crédit. Historique conservé 90 jours.',
                $credits['used'], $credits['monthly'], $credits['remaining'],
                (new \DateTimeImmutable($credits['resetAt']))->format('d/m/Y')
            ) . $cost)
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    /** Plus récentes d'abord (la base trie par id croissant) */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->resetDQLPart('orderBy')
            ->orderBy('entity.createdAt', 'DESC')
            ->addOrderBy('entity.id', 'DESC');
    }

    public function configureFields(string $pageName): iterable
    {
        $fields = [
            DateTimeField::new('createdAt', 'Date')->setFormat('dd/MM/yyyy HH:mm:ss'),
            TextField::new('user', 'Utilisateur'),
            TextField::new('mode', 'Mode'),
            TextField::new('componentKey', 'Famille'),
            ChoiceField::new('status', 'Statut')->setChoices(self::STATUSES)->renderAsBadges([
                AiUsage::STATUS_SUCCESS => 'success',
                AiUsage::STATUS_FAILED => 'danger',
                AiUsage::STATUS_EXPIRED => 'secondary',
                AiUsage::STATUS_RESERVED => 'warning',
            ]),
            IntegerField::new('credits', 'Crédits'),
            IntegerField::new('attempts', 'Essais'),
            IntegerField::new('durationMs', 'Durée (ms)'),
            TextField::new('promptExcerpt', 'Demande')->setMaxLength(80)->onlyOnIndex(),
            TextField::new('promptExcerpt', 'Demande (500 premiers caractères)')->onlyOnDetail(),
            TextField::new('model', 'Modèle')->onlyOnDetail(),
            IntegerField::new('inputTokens', 'Jetons en entrée')->onlyOnDetail(),
            IntegerField::new('cacheReadTokens', 'Jetons lus en cache')->onlyOnDetail(),
            IntegerField::new('cacheWriteTokens', 'Jetons écrits en cache (compris dans l\'entrée)')->onlyOnDetail(),
            IntegerField::new('outputTokens', 'Jetons en sortie')->onlyOnDetail(),
            DateTimeField::new('completedAt', 'Terminée le')->setFormat('dd/MM/yyyy HH:mm:ss')->onlyOnDetail(),
            TextField::new('tenant', 'Site')->onlyOnDetail(),
        ];
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            // champ calculé : s'appuie sur une propriété existante, la valeur affichée vient de formatValue
            array_splice($fields, 6, 0, [
                TextField::new('model', 'Coût estimé ($ US)')
                    ->formatValue(fn ($value, AiUsage $usage) => $usage->getAttempts() > 0 ? number_format($this->pricing->usageCost($usage), 3, ',', ' ') : '—')
                    ->setSortable(false),
            ]);
        }

        return $fields;
    }
}
