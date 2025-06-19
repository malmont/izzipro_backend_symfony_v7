<?php

namespace App\Controller\Admin;

use App\Entity\Caisse;
use App\Entity\Order;
use App\Entity\Payments;
use App\Entity\TransactionCaisse;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Controller\Admin\BaseTenantCrudController; // <-- On importe notre base
use App\Repository\CaisseRepository;
use App\Repository\TransactionTypeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

// 1. On étend notre contrôleur de base
class TransactionCaisseListController extends BaseTenantCrudController
{
    private RequestStack $requestStack;
    private AdminUrlGenerator $adminUrlGenerator;

    // 2. Le constructeur appelle le parent et stocke ses propres dépendances
    public function __construct(
        TenantEntityManagerProvider $emProvider, // Requis par le parent
        RequestStack $requestStack,
        AdminUrlGenerator $adminUrlGenerator
    ) {
        parent::__construct($emProvider);
        $this->requestStack = $requestStack;
        $this->adminUrlGenerator = $adminUrlGenerator;
    }

    public static function getEntityFqcn(): string
    {
        return TransactionCaisse::class;
    }

    // 3. On conserve NOTRE surcharge car elle a une logique personnalisée
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $request = $this->requestStack->getCurrentRequest();
        $caisseId = $request->query->get('caisseId');
        
        $qb = $tenantEm->getRepository(TransactionCaisse::class)
            ->createQueryBuilder('tc');

        if ($caisseId) {
            $qb->where('tc.caisse = :caisseId')
               ->setParameter('caisseId', $caisseId);
        }

        return $qb;
    }
    
    // 4. On conserve configureFields car il est spécifique à cette entité
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
            DateField::new('transactionDate', 'Date de Transaction'),
            MoneyField::new('amount', 'Montant')->setCurrency('USD')->setStoredAsCents(false),      
            AssociationField::new('cashdetails', 'Détails de cash')
                ->formatValue(function ($value, $entity) {
                    // ...
                })->renderAsHtml(),
        ];
    }
    
}