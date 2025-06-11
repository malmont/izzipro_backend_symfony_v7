<?php

namespace App\Repository;

use App\Entity\Entreprise;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<Entreprise>
 */
class EntrepriseRepository extends EntityRepository
{
    /**
     * SUPPRIMÉ : Le constructeur n'est plus nécessaire.
     */
    // public function __construct(ManagerRegistry $registry) { ... }
}