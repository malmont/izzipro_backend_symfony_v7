<?php

namespace App\Repository;

use App\Entity\Collections;
use Doctrine\ORM\EntityRepository; 


class CollectionsRepository extends EntityRepository
{

    public function save(Collections $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Collections $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Liste filtrée (lot G5, 10/11/2026) : `status` open | closed | deleted | all (null : ouvertes et fermées, hors supprimées),
     * `q` sur le nom, pagination facultative.
     *
     * @return array{items: list<Collections>, total: int}
     */
    public function search(?string $status, ?string $q, ?int $page, ?int $limit): array
    {
        $qb = $this->createQueryBuilder('c')->orderBy('c.id', 'ASC');
        match ($status) {
            'open' => $qb->andWhere('c.del = false')->andWhere('c.isClosed = false OR c.isClosed IS NULL'),
            'closed' => $qb->andWhere('c.del = false')->andWhere('c.isClosed = true'),
            'deleted' => $qb->andWhere('c.del = true'),
            'all' => $qb,
            default => $qb->andWhere('c.del = false'),
        };
        if ($q !== null && $q !== '') {
            $qb->andWhere('LOWER(c.nomCollection) LIKE :q')->setParameter('q', '%' . mb_strtolower($q) . '%');
        }
        $total = (int) (clone $qb)->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        if ($page !== null && $limit !== null) {
            $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        }

        return ['items' => $qb->getQuery()->getResult(), 'total' => $total];
    }
}
