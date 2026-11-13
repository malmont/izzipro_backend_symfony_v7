<?php

namespace App\Controller\Admin;

use App\Services\SiteContentService\DemoContentMarker;
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
        $crud = parent::configureCrud($crud)->setDefaultSort(['id' => 'ASC']);
        // Guide des composants (08/11/2026) : nom explicite de l'écran ; un contrôleur qui pose ses propres libellés après parent::configureCrud() garde les siens
        if (($guide = \App\Services\Admin\ComponentGuideCatalog::for(static::class)) !== null) {
            $crud->setEntityLabelInSingular($guide['singular'])->setEntityLabelInPlural($guide['title'])->setPageTitle(Crud::PAGE_INDEX, $guide['title']);
        }

        return $crud;
    }

    /**
     * Gère la LISTE avec la recherche, les filtres et le tri de l'écran : la requête d'EasyAdmin elle-même (lot EA-LISTES, 13/11/2026).
     * Avant, cette méthode rendait une requête nue triée par identifiant, qui ignorait les trois ; le tri par défaut d'un écran
     * (`setDefaultSort`) n'était donc pas appliqué non plus. La requête d'EasyAdmin lit bien la base du site : le gestionnaire par
     * défaut de Doctrine EST celui du site (sa connexion est basculée à chaque requête, `TenantConnectionProvider`).
     *
     * Un tri demandé dans l'adresse sur une colonne sans ordre possible (JSON, association vue du côté inverse) est écarté : la liste
     * s'affiche avec ses autres tris, au lieu d'une erreur de la base (`UnsortableFieldConfigurator`).
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $sort = array_filter($searchDto->getSort(), fn (string $property) => !\App\Services\Admin\UnsortableFieldConfigurator::isUnsortable($entityDto, $property), ARRAY_FILTER_USE_KEY);
        if ($sort !== $searchDto->getSort()) {
            $searchDto = new SearchDto($searchDto->getRequest(), $searchDto->getSearchableProperties(), $searchDto->getQuery(), $sort, [], $searchDto->getAppliedFilters(), $searchDto->getSearchMode());
        }

        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
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
        try {
            $tenantEm->flush();
        } catch (\App\Services\RetentionService\RetentionViolationException $e) { // mode « block » (A1)
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException($e->getMessage(), $e);
        }
        if (DemoContentMarker::customized($entityInstance)) { // une fiche d'exemple modifiée devient définitive
            $tenantEm->flush();
        }
    }

    /**
     * Gère la SUPPRESSION en utilisant l'EM du tenant.
     */
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $managedEntity = $tenantEm->contains($entityInstance) ? $entityInstance : $tenantEm->merge($entityInstance);
        $tenantEm->remove($managedEntity);
        try {
            $tenantEm->flush();
        } catch (\App\Services\RetentionService\RetentionViolationException $e) { // conservation des données de vente (A1) : message clair
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException($e->getMessage(), $e);
        } catch (\App\Gestion\Services\GestionConflictException $e) { // article ou variante avec une histoire (ART-SUPPR) : même message qu'à l'API
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException($e->getMessage(), $e);
        }
    }
}