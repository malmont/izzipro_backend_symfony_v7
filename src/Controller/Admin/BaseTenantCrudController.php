<?php

namespace App\Controller\Admin;

// Le namespace de votre service, tel que vous l'avez fourni.
use App\Services\TenantEntityManagerProvider; 
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
// AJOUTS NÉCESSAIRES POUR LA SOLUTION 2
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Symfony\Component\Form\FormBuilderInterface;

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
        // 1. On charge manuellement la "bonne" entité depuis l'EM du tenant
        $tenantEm = $this->emProvider->getEntityManager();
        $entityInstance = $tenantEm->find($entityDto->getFqcn(), $entityDto->getPrimaryKeyValue());
        
        // 2. On met à jour l'instance dans le DTO (Data Transfer Object) d'EasyAdmin
        $entityDto->setInstance($entityInstance);
        
        // 3. On appelle la méthode parente, mais avec le DTO que nous venons de corriger
        return parent::createEditFormBuilder($entityDto, $formOptions, $context);
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
        // Avec la correction ci-dessus, l'entityInstance est maintenant correctement
        // suivie par l'EntityManager du tenant. Un simple flush suffit.
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