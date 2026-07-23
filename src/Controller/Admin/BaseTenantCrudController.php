<?php

namespace App\Controller\Admin;

use App\Services\TenantEntityManagerProvider; 
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

/**
 * Ce contrôleur de base est étendu par tous les CrudController de tenant.
 * Il assure que les opérations s'exécutent sur l'EntityManager du tenant courant.
 */
abstract class BaseTenantCrudController extends AbstractCrudController
{
    protected TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setDefaultSort(['id' => 'ASC']);
    }

    /**
     * Gère la LISTE en utilisant l'EM du tenant.
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $qb = $tenantEm->getRepository($entityDto->getFqcn())->createQueryBuilder('entity');
        $qb->addOrderBy('entity.id', 'ASC');
        return $qb;
    }

    /**
     * Gère la CRÉATION en utilisant l'EM du tenant.
     */
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->persist($entityInstance);
        $tenantEm->flush();
    }

    /**
     * Gère la MISE À JOUR en utilisant l'EM du tenant.
     */
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->flush();
    }

    /**
     * Gère la SUPPRESSION en utilisant l'EM du tenant.
     */
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $managedEntity = $tenantEm->contains($entityInstance) ? $entityInstance : $tenantEm->merge($entityInstance);
        $tenantEm->remove($managedEntity);
        $tenantEm->flush();
    }
}