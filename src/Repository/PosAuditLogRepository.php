<?php

namespace App\Repository;

use App\Entity\PosAuditLog;
use Doctrine\ORM\EntityRepository;

/** Journal d'audit de la caisse (lecture) ; l'écriture passe par PosAuditService */
class PosAuditLogRepository extends EntityRepository
{
    /**
     * @param list<string> $types
     * @return array{0: list<PosAuditLog>, 1: int} entrées de la page (plus récentes d'abord) et total
     */
    public function search(?int $cashRegisterId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, array $types, int $page, int $pageSize): array
    {
        $qb = $this->createQueryBuilder('a');
        if ($cashRegisterId !== null) {
            $qb->andWhere('a.cashRegisterId = :register')->setParameter('register', $cashRegisterId);
        }
        if ($from) {
            $qb->andWhere('a.occurredAt >= :from')->setParameter('from', $from);
        }
        if ($to) {
            $qb->andWhere('a.occurredAt <= :to')->setParameter('to', $to);
        }
        if ($types) {
            $qb->andWhere('a.type IN (:types)')->setParameter('types', $types);
        }
        $total = (int) (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
        $rows = $qb->orderBy('a.sequence', 'DESC')->setFirstResult(($page - 1) * $pageSize)->setMaxResults($pageSize)->getQuery()->getResult();

        return [$rows, $total];
    }
}
