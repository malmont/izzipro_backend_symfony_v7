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

/**
 * Ce contrôleur de base DOIT être étendu par tous les CrudController de tenant.
 * Il contient toute la logique pour utiliser l'EntityManager du tenant.
 */
abstract class BaseTenantCrudController extends AbstractCrudController
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    /**
     * Gère la LISTE en utilisant l'EM du tenant.
     * Cette méthode est générique car elle utilise getEntityFqcn() de la classe enfant.
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository($entityDto->getFqcn())->createQueryBuilder('entity');
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
        // On utilise merge() par sécurité si l'entité est détachée
        $managedEntity = $tenantEm->merge($entityInstance);
        $tenantEm->remove($managedEntity);
        $tenantEm->flush();
    }
}