<?php

namespace App\Repository;

use App\Entity\AppProfile;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<AppProfile> */
class AppProfileRepository extends EntityRepository
{
    public function findOneByApp(string $app): ?AppProfile
    {
        return $this->findOneBy(['app' => $app]);
    }
}
