<?php

namespace App\Repository;

use App\Entity\Collections;
use App\Entity\CollectionStatistiques;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<CollectionStatistiques>
 */
class CollectionStatistiquesRepository extends EntityRepository
{
    /**
     * SUPPRIMÉ : Le constructeur n'est plus nécessaire.
     */
    // public function __construct(ManagerRegistry $registry) { ... }

    /**
     * INCHANGÉ : Votre méthode personnalisée fonctionnera parfaitement car
     * $this->createQueryBuilder() utilisera maintenant l'EntityManager du tenant.
     */
    public function findLatestByCollection(Collections $collection): ?CollectionStatistiques
    {
        return $this->createQueryBuilder('cs')
            ->andWhere('cs.collection = :collection')
            ->setParameter('collection', $collection)
            ->orderBy('cs.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}