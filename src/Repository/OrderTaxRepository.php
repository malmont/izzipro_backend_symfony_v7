<?php

namespace App\Repository;

use App\Entity\OrderTax;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base
use DateTime;

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<OrderTax>
 */
class OrderTaxRepository extends EntityRepository
{
}