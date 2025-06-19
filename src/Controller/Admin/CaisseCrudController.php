<?php

namespace App\Controller\Admin;

use App\Entity\Caisse;
use App\Entity\TransactionCaisse;
use App\Entity\TransactionType;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Security\Core\Security;
// ✅ DÉBUT DU BLOC DE USE CORRIGÉ
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
// ✅ FIN DU BLOC DE USE CORRIGÉ

class CaisseCrudController extends AbstractCrudController
{
    private TenantEntityManagerProvider $emProvider;
    private Security $security;
    private AdminUrlGenerator $adminUrlGenerator;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        Security $security,
        AdminUrlGenerator $adminUrlGenerator
    ) {
        $this->emProvider = $emProvider;
        $this->security = $security;
        $this->adminUrlGenerator = $adminUrlGenerator;
    }

    public static function getEntityFqcn(): string
    {
        return Caisse::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Caisse')
            ->setEntityLabelInPlural('Caisses')
            ->setSearchFields(['amountTotal', 'createdAt']);
    }

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

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('amountTotal')
            ->add('createdAt');
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Caisse::class)->createQueryBuilder('c');
    }

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

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->merge($entityInstance);
        $tenantEm->flush();
    }
    
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $managedEntity = $tenantEm->merge($entityInstance);
        $tenantEm->remove($managedEntity);
        $tenantEm->flush();
    }

    private function getOpenCaisse(): ?Caisse
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Caisse::class)->findOneBy(['isOpen' => true]);
    }
}