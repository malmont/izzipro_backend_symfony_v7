<?php

namespace App\Repository;

use App\Entity\Marque;
use Doctrine\ORM\EntityRepository;

/**
 * @extends ServiceEntityRepository<Marque>
 */
class MarqueRepository extends EntityRepository
{
    /**
     * @return Marque[]
     */
    public function findAllWithCategories(): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.categories', 'c')
            ->addSelect('c')
            ->orderBy('m.titre', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
