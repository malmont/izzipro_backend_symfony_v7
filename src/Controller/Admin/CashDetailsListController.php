<?php
namespace App\Controller\Admin;

use App\Entity\CashDetails;
use App\Entity\TransactionCaisse;
use App\Entity\TypeCash;
use App\Repository\TransactionCaisseRepository;
use App\Repository\TypeCashRepository;
use App\Services\TenantEntityManagerProvider;
use App\Controller\Admin\BaseTenantCrudController;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use Symfony\Component\HttpFoundation\RequestStack;


class CashDetailsListController extends BaseTenantCrudController
{
    private RequestStack $requestStack;

    public function __construct(
        TenantEntityManagerProvider $emProvider, 
        RequestStack $requestStack
    ) {
        parent::__construct($emProvider);
        $this->requestStack = $requestStack;
    }

    public static function getEntityFqcn(): string
    {
        return CashDetails::class;
    }

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
}