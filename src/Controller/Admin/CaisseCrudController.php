<?php
namespace App\Controller\Admin;

use App\Entity\Caisse;
use App\Entity\CashRegister;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;
use App\Controller\Admin\BaseTenantCrudController;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Hérite de la base pour la logique CRUD (index, edit, update, delete).
 * Surcharge uniquement ce qui est spécifique : constructeur, configuration et la création.
 */
class CaisseCrudController extends BaseTenantCrudController
{
    private Security $security;
    private AdminUrlGenerator $adminUrlGenerator;

    public function __construct(
        TenantEntityManagerProvider $emProvider, // Requis par le parent
        Security $security,
        AdminUrlGenerator $adminUrlGenerator,
        private readonly CashRegisterResolver $registers,
        private readonly HandleCaisseTransactionUseCase $transactions
    ) {
        parent::__construct($emProvider);
        $this->security = $security;
        $this->adminUrlGenerator = $adminUrlGenerator;
    }

    public static function getEntityFqcn(): string
    {
        return Caisse::class;
    }

    /**
     * On conserve votre configuration de CRUD personnalisée.
     */
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Caisse')
            ->setEntityLabelInPlural('Caisses')
            ->setSearchFields(['amountTotal', 'createdAt']);
    }

    /**
     * On conserve votre configuration de champs personnalisée.
     */
    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            // Poste de la session (15/10/2026) ; un seul poste sur le site : préselectionné, l'écran reste celui d'avant
            AssociationField::new('cashRegister', 'Poste de caisse')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn ($repo) => $repo->createQueryBuilder('r')->andWhere('r.isActive = true')->orderBy('r.id', 'ASC'),
                    'choice_label' => 'name',
                ]),
            MoneyField::new('amountTotal', 'Montant Total')->setCurrency('USD')->setStoredAsCents(false),
            DateTimeField::new('createdAt', 'Ouverte le')->setFormat('dd/MM/yyyy HH:mm')->hideOnForm(),
            MoneyField::new('fonDeCaisse', 'Fond de Caisse')->setCurrency('USD')->setStoredAsCents(false),
            BooleanField::new('isOpen', 'Ouverte')->renderAsSwitch(false),
            DateTimeField::new('closedAt', 'Fermée le')->hideOnForm(),
            AssociationField::new('transactionCaisses', 'Transactions')
                ->formatValue(function ($value, $entity) {
                    $transactionCaissesUrl = $this->adminUrlGenerator
                        ->setController(TransactionCaisseListController::class)
                        ->setAction('index')
                        ->set('caisseId', $entity->getId())
                        ->generateUrl();

                    return sprintf(
                        '<a href="%s" style="text-decoration: none; color: #007bff;">Voir les transactions</a>',
                        $transactionCaissesUrl
                    );
                })
                ->renderAsHtml()
        ];
    }

    /**
     * On conserve votre configuration de filtres personnalisée.
     */
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('amountTotal')
            ->add('createdAt');
    }

    /**
     * On conserve votre logique de création personnalisée.
     */
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();

        if ($entityInstance instanceof Caisse) {
            // Poste choisi dans le formulaire, sinon le poste unique du site (règle CashRegisterResolver)
            $register = $entityInstance->getCashRegister() ?? $this->registers->resolve(null);
            if ($this->registers->openSession($register)) {
                throw new CashRegisterException('CAISSE_ALREADY_OPEN', sprintf('Une session est déjà ouverte sur le poste « %s ». Fermez-la avant d\'en ouvrir une autre.', $register->getName()), 400);
            }
            $entityInstance->setCashRegister($register);
            $entityInstance->setOpen(true);
            $entityInstance->setCreatedAt(new \DateTime());
            $entityInstance->setAmountTotal((float) ($entityInstance->getAmountTotal() ?? 0));
            $entityInstance->setFonDeCaisse((float) ($entityInstance->getFonDeCaisse() ?? 0));

            $tenantEm->persist($entityInstance);
            $tenantEm->flush();

            // Transaction d'ouverture (type 4), comme par l'API
            $this->transactions->execute($entityInstance, null, $this->security->getUser(), 0.0, HandleCaisseTransactionUseCase::OPENING);
        }
    }

    /** Fermeture depuis le formulaire (interrupteur « Ouverte ») : date de fermeture posée */
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Caisse && !$entityInstance->isOpen() && !$entityInstance->getClosedAt()) {
            $entityInstance->setClosedAt(new \DateTime());
        }
        parent::updateEntity($entityManager, $entityInstance);
    }
    
    // Les méthodes createIndexQueryBuilder, updateEntity, et deleteEntity ne sont plus nécessaires ici,
    // car la version standard du BaseTenantCrudController est suffisante.
}