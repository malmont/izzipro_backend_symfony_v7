<?php
namespace App\Controller\Admin;

use App\Entity\CashDetails;
use App\Entity\TransactionCaisse;
use App\Entity\TypeCash;
use App\Repository\TransactionCaisseRepository;
use App\Repository\TypeCashRepository;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use Symfony\Component\HttpFoundation\RequestStack;

class CashDetailsListController extends AbstractCrudController
{
    /**
     * 1. On injecte notre provider
     */
    private TenantEntityManagerProvider $emProvider;
    private RequestStack $requestStack;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        RequestStack $requestStack
    ) {
        $this->emProvider = $emProvider;
        $this->requestStack = $requestStack;
    }

    public static function getEntityFqcn(): string
    {
        return CashDetails::class;
    }

    /**
     * 2. La requête de liste est maintenant "tenant-aware"
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $request = $this->requestStack->getCurrentRequest();
        $transactionId = $request->query->get('transactionId');

        $qb = $tenantEm->getRepository(CashDetails::class)
            ->createQueryBuilder('cd');
        
        if ($transactionId) {
            $qb->where('cd.transactionCaisse = :transactionId')
               ->setParameter('transactionId', $transactionId);
        }

        return $qb;
    }

    /**
     * 3. Les champs d'association sont maintenant "tenant-aware"
     */
    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            AssociationField::new('transactionCaisse', 'Transaction')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (TransactionCaisseRepository $repo) {
                        return $repo->createQueryBuilder('tc')->orderBy('tc.transactionDate', 'DESC');
                    },
                    'choice_label' => 'id',
                ]),
            AssociationField::new('typeCash', 'Type de Cash')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (TypeCashRepository $repo) {
                        return $repo->createQueryBuilder('tc')->orderBy('tc.name', 'ASC');
                    },
                    'choice_label' => 'name',
                ]),
            IntegerField::new('nombreItems', 'Nombre d\'items'),
        ];
    }

    /**
     * 4. On surcharge les méthodes d'écriture par sécurité
     */
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->persist($entityInstance);
        $tenantEm->flush();
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
}