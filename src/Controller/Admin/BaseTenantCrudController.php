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
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Symfony\Component\Form\FormBuilderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

/**
 * Ce contrôleur de base DOIT être étendu par tous les CrudController de tenant.
 * Il contient toute la logique pour utiliser l'EntityManager du tenant.
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

    public function detail(AdminContext $context)
    {
        $tenantEm = $this->emProvider->getEntityManager();
        
        // On cherche la bonne entité dans la BDD du tenant
        $entityInstance = $tenantEm->find(
            $context->getEntity()->getFqcn(),
            $context->getEntity()->getPrimaryKeyValue()
        );

        if (!$entityInstance) {
            throw $this->createNotFoundException();
        }
        
        // On remplace l'entité dans le contexte avant d'afficher la page
        $context->getEntity()->setInstance($entityInstance);

        return parent::detail($context);
    }

    // =========================================================================
    // == DÉBUT DE LA CORRECTION POUR LE BUG D'ÉDITION (SOLUTION 2)
    // =========================================================================

    /**
     * Cette méthode intercepte la création du formulaire d'édition.
     * Elle force le rechargement de l'entité via l'EntityManager du tenant
     * AVANT que le formulaire ne soit construit, garantissant que les
     * bonnes données sont utilisées pour pré-remplir les champs.
     */
     public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
        {
            $tenantEm = $this->emProvider->getEntityManager();
            $correctEntity = $tenantEm->find($entityDto->getFqcn(), $entityDto->getPrimaryKeyValue());
            
            if (!$correctEntity) {
                throw $this->createNotFoundException('Entity not found in this tenant for editing.');
            }
            $entityDto->setInstance($correctEntity);
            
            $formBuilder = parent::createEditFormBuilder($entityDto, $formOptions, $context);
            
            $formBuilder->setData($correctEntity);
            
            return $formBuilder;
        }
    
    // =========================================================================
    // == FIN DE LA CORRECTION
    // =========================================================================


    /**
     * Gère la LISTE en utilisant l'EM du tenant.
     * Cette méthode est générique car elle utilise getEntityFqcn() de la classe enfant.
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
        $managedEntity = $tenantEm->merge($entityInstance);
        $tenantEm->remove($managedEntity);
        $tenantEm->flush();
    }
}