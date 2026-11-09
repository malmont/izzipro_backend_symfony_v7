<?php

namespace App\Repository;

use App\Entity\NoteDeFrais;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base
use DateTime;

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<NoteDeFrais>
 */
class NoteDeFraisRepository extends EntityRepository
{


}