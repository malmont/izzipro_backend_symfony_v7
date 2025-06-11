<?php

namespace App\Repository;

use App\Entity\EmailConfiguration;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<EmailConfiguration>
 */
class EmailConfigurationRepository extends EntityRepository
{
    /**
     * SUPPRIMÉ : Le constructeur n'est plus nécessaire.
     */
    // public function __construct(ManagerRegistry $registry) { ... }


    // Les méthodes commentées restent ici, inchangées.
    // Si vous en aviez besoin, elles fonctionneraient maintenant correctement.
}