<?php

namespace App\Repository;

use App\Entity\Commande;
use App\Entity\CommandeStatistiques;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<CommandeStatistiques>
 */
class CommandeStatistiquesRepository extends EntityRepository
{
    /**
     * SUPPRIMÉ : Le constructeur n'est plus nécessaire.
     */
    // public function __construct(ManagerRegistry $registry) { ... }


    /**
     * INCHANGÉ : Votre méthode personnalisée fonctionnera parfaitement car
     * $this->createQueryBuilder() utilisera maintenant l'EntityManager du tenant.
     */
    public function findLatestByCommande(Commande $commande): ?CommandeStatistiques
    {
        return $this->createQueryBuilder('cs')
            ->andWhere('cs.commande = :commande')
            ->setParameter('commande', $commande)
            ->orderBy('cs.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}