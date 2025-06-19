<?php

namespace App\Controller\Admin;

use App\Entity\Caisse;
use App\Entity\TransactionCaisse;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Repository\CaisseRepository;
use App\Repository\TransactionTypeRepository;
use App\Repository\UserRepository;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

class TransactionCaisseCrudController extends AbstractCrudController
{
    private TenantEntityManagerProvider $emProvider;
    private AdminUrlGenerator $adminUrlGenerator;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        AdminUrlGenerator $adminUrlGenerator
    ) {
        $this->emProvider = $emProvider;
        $this->adminUrlGenerator = $adminUrlGenerator;
    }

    public static function getEntityFqcn(): string
    {
        return TransactionCaisse::class;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(TransactionCaisse::class)->createQueryBuilder('tc');
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            AssociationField::new('caisse', 'Caisse')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (CaisseRepository $repo) {
                        return $repo->createQueryBuilder('c')->orderBy('c.createdAt', 'DESC');
                    },
                    'choice_label' => 'id',
                ]),
            AssociationField::new('userCaisse', 'Utilisateur')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (UserRepository $repo) {
                        return $repo->createQueryBuilder('u')->orderBy('u.email', 'ASC');
                    },
                    'choice_label' => 'email',
                ]),
            AssociationField::new('transactionType', 'Type de transaction')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (TransactionTypeRepository $repo) {
                        return $repo->createQueryBuilder('tt')->orderBy('tt.name', 'ASC');
                    },
                    'choice_label' => 'name',
                ]),
            DateField::new('transactionDate', 'Date de transaction'),
            MoneyField::new('amount', 'Montant')->setCurrency('USD')->setStoredAsCents(false),
            AssociationField::new('cashdetails', 'Détails de cash')
                ->formatValue(function ($value, $entity) {
                    $cashDetailsUrl = $this->adminUrlGenerator
                        ->setController(CashDetailsListController::class)
                        ->setAction(Crud::PAGE_INDEX)
                        ->set('transactionId', $entity->getId())
                        ->generateUrl();

                    return sprintf(
                        '<a href="%s" style="text-decoration: none; color: #007bff;">Voir les détails de cash</a>',
                        $cashDetailsUrl
                    );
                })
                ->renderAsHtml(),
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->persist($entityInstance);
        $tenantEm->flush();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->flush();
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $managedEntity = $tenantEm->merge($entityInstance);
        $tenantEm->remove($managedEntity);
        $tenantEm->flush();
    }
}