<?php
// src/Controller/Admin/ProductOptionValueListController.php

namespace App\Controller\Admin;

use App\Entity\ProductOptionValue;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\RequestStack;

// On étend la base pour le multi-tenant, comme pour les autres contrôleurs
class ProductOptionValueListController extends BaseTenantCrudController
{
    private RequestStack $requestStack;

    public function __construct(TenantEntityManagerProvider $emProvider, RequestStack $requestStack)
    {
        parent::__construct($emProvider);
        $this->requestStack = $requestStack;
    }

    public static function getEntityFqcn(): string
    {
        return ProductOptionValue::class;
    }


    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $request = $this->requestStack->getCurrentRequest();
        $variantId = $request->query->get('variantId');

        $qb = $tenantEm->getRepository(ProductOptionValue::class)->createQueryBuilder('pov');

        if ($variantId) {
            $qb->innerJoin('pov.productVariants', 'pv')
               ->where('pv.id = :variantId')
               ->setParameter('variantId', $variantId);
        }

        return $qb;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('value', 'Attribut et Valeur')->formatValue(function ($value, ProductOptionValue $entity) {
                $parentOption = $entity->getProductOption();
                if ($parentOption && $parentOption->getId() === 5) {

                    return $entity->__toString() . ' $';

                }
                return $entity->__toString();
            }),
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }
    
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'Valeurs pour la variante')
            ->showEntityActionsInlined();
    }
}