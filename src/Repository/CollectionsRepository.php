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
    public function search(?string $status, ?string $q, ?int $page, ?int $limit, ?string $kind = null, ?\DateTimeInterface $today = null): array
    {
        $qb = $this->createQueryBuilder('c')->orderBy('c.startDateCollection', 'DESC')->addOrderBy('c.id', 'DESC');
        // période fermée par la date : fin + 15 jours < aujourd'hui, sans réouverture ; ou clôture manuelle (lot P1, 10/11/2026)
        $edge = \DateTimeImmutable::createFromInterface($today ?? new \DateTimeImmutable('today'))->setTime(0, 0)->modify('-' . \App\Gestion\Services\PeriodCalendar::GRACE_DAYS . ' days')->format('Y-m-d 00:00:00');
        $periodClosed = "(c.kind = 'period' AND (c.closedAt IS NOT NULL OR (c.reopenedAt IS NULL AND c.endDateCollection < :edge)))";
        $manualClosed = "(c.kind <> 'period' AND c.isClosed = true)";
        match ($status) {
            'open' => $qb->andWhere('c.del = false')->andWhere("NOT ($periodClosed OR $manualClosed)")->setParameter('edge', $edge),
            'closed' => $qb->andWhere('c.del = false')->andWhere("$periodClosed OR $manualClosed")->setParameter('edge', $edge),
            'deleted' => $qb->andWhere('c.del = true'),
            'all' => $qb,
            default => $qb->andWhere('c.del = false'),
        };
        if ($kind !== null) {
            $qb->andWhere('c.kind = :kind')->setParameter('kind', $kind);
        }
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
