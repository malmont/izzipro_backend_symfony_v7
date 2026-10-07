<?php

namespace App\Repository;

use App\Entity\LandingSiteModel;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<LandingSiteModel>
 */
class LandingSiteModelRepository extends EntityRepository
{
    /** @return LandingSiteModel[] du plus récent au plus ancien */
    public function findLatestFirst(): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.updatedAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('m')->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();
    }
}
