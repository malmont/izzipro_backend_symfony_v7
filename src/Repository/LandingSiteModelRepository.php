<?php

namespace App\Repository;

use App\Entity\LandingSiteModel;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<LandingSiteModel>
 */
class LandingSiteModelRepository extends EntityRepository
{
    /** @return LandingSiteModel[] modèles d'une application, du plus récent au plus ancien */
    public function findLatestFirst(string $app): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.app = :app')->setParameter('app', $app)
            ->orderBy('m.updatedAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countAll(string $app): int
    {
        return (int) $this->createQueryBuilder('m')->select('COUNT(m.id)')
            ->andWhere('m.app = :app')->setParameter('app', $app)
            ->getQuery()->getSingleScalarResult();
    }
}
