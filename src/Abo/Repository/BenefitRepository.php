<?php

namespace App\Abo\Repository;

use App\Abo\Entity\Benefit;
use Doctrine\ORM\EntityRepository;

/** Prestations du site (lot ABO-0) ; obtenu par TenantEntityManagerProvider */
class BenefitRepository extends EntityRepository
{
    /** @return list<Benefit> */
    public function ordered(bool $activeOnly = false): array
    {
        $qb = $this->createQueryBuilder('b')->orderBy('b.position', 'ASC')->addOrderBy('b.id', 'ASC');
        if ($activeOnly) {
            $qb->where('b.active = true');
        }

        return $qb->getQuery()->getResult();
    }
}
