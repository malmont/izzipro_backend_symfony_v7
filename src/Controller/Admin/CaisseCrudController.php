<?php
namespace App\Controller\Admin;

use App\Entity\Caisse;
use App\Entity\TransactionCaisse;
use App\Entity\TransactionType;
use App\Services\TenantEntityManagerProvider;
use App\Controller\Admin\BaseTenantCrudController;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
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
        AdminUrlGenerator $adminUrlGenerator
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
        return [
            MoneyField::new('amountTotal', 'Montant Total')->setCurrency('USD')->setStoredAsCents(false),
            DateField::new('createdAt', 'Date de Création')->setFormat('dd/MM/yyyy')->hideOnForm(),
            MoneyField::new('fonDeCaisse', 'Fond de Caisse')->setCurrency('USD')->setStoredAsCents(false),
            BooleanField::new('isOpen', 'Ouverte')->renderAsSwitch(false),
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
            $existingCaisse = $this->getOpenCaisse();
            if ($existingCaisse) {
                throw new \Exception('A caisse is already open. Please close it before opening a new one.');
            }

            $entityInstance->setOpen(true);
            $entityInstance->setCreatedAt(new \DateTime());

            $tenantEm->persist($entityInstance);
            $tenantEm->flush();

            $transactionType = $tenantEm->getRepository(TransactionType::class)->findOneBy(['name' => 'Ouverture']);
            if (!$transactionType) {
                throw new \Exception("Le type de transaction 'Ouverture' est introuvable.");
            }
            $user = $this->security->getUser();

            $transactionCaisse = new TransactionCaisse();
            $transactionCaisse->setCaisse($entityInstance);
            $transactionCaisse->setUserCaisse($user);
            $transactionCaisse->setTransactionDate(new \DateTime());
            $transactionCaisse->setTransactionType($transactionType);
            $transactionCaisse->setAmount(0.0);

            $tenantEm->persist($user);
            $tenantEm->persist($transactionType);
            
            $tenantEm->persist($transactionCaisse);
            $tenantEm->flush();
        }
    }

    private function getOpenCaisse(): ?Caisse
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Caisse::class)->findOneBy(['isOpen' => true]);
    }
    
    // Les méthodes createIndexQueryBuilder, updateEntity, et deleteEntity ne sont plus nécessaires ici,
    // car la version standard du BaseTenantCrudController est suffisante.
}