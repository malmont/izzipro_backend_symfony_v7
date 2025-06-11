<?php

namespace App\Repository;

use App\Entity\OtpCode;
use Doctrine\ORM\EntityRepository; // MODIFIED: Use Doctrine's base repository

/**
 * This is no longer a Symfony service.
 * @extends EntityRepository<OtpCode>
 */
class OtpCodeRepository extends EntityRepository
{
    /**
     * DELETED: The constructor is no longer needed.
     */
    // public function __construct(ManagerRegistry $registry) { ... }
}