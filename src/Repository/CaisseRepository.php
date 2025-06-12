<?php

namespace App\Repository;

use App\Entity\Caisse;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<Caisse>
 */
class CaisseRepository extends EntityRepository
{
    /**
     * SUPPRIMÉ : Le constructeur n'est plus nécessaire.
     */
    // public function __construct(ManagerRegistry $registry) { ... }


    /**
     * INCHANGÉ : Votre méthode personnalisée fonctionnera parfaitement car
     * $this->createQueryBuilder() utilisera maintenant l'EntityManager
     * fourni par le TenantEntityManagerProvider.
     */
    public function getLastClosedCaisse(): ?Caisse
    {
        return $this->createQueryBuilder('c')
            ->where('c.isOpen = :isOpen')
            ->setParameter('isOpen', false)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC') // Critère secondaire
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}