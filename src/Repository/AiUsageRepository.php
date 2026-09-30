<?php

namespace App\Repository;

use App\Entity\AiUsage;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<AiUsage>
 */
class AiUsageRepository extends EntityRepository
{
    /** Crédits engagés depuis une date : consommés, ou réservés et encore valides à $now */
    public function sumCommittedCredits(\DateTimeImmutable $since, \DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COALESCE(SUM(u.credits), 0)')
            ->where('u.createdAt >= :since')
            ->andWhere('u.status = :success OR (u.status = :reserved AND u.reservedUntil >= :now)')
            ->setParameter('since', $since)
            ->setParameter('success', AiUsage::STATUS_SUCCESS)
            ->setParameter('reserved', AiUsage::STATUS_RESERVED)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Réservations dont l'échéance est passée (processus interrompu) : crédits libérés */
    public function expireReservations(\DateTimeImmutable $now): int
    {
        return $this->createQueryBuilder('u')
            ->update()
            ->set('u.status', ':expired')
            ->set('u.completedAt', ':now')
            ->where('u.status = :reserved')
            ->andWhere('u.reservedUntil < :now OR u.reservedUntil IS NULL')
            ->setParameter('expired', AiUsage::STATUS_EXPIRED)
            ->setParameter('now', $now)
            ->setParameter('reserved', AiUsage::STATUS_RESERVED)
            ->getQuery()
            ->execute();
    }

    /** Demandes échouées depuis $since après au moins un appel à l'IA (attempts > 0) */
    public function countFailedWithCallsSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.status = :failed AND u.attempts > 0 AND u.createdAt >= :since')
            ->setParameter('failed', AiUsage::STATUS_FAILED)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function oldestFailedWithCallsSince(\DateTimeImmutable $since): ?\DateTimeImmutable
    {
        $oldest = $this->createQueryBuilder('u')
            ->select('MIN(u.createdAt)')
            ->where('u.status = :failed AND u.attempts > 0 AND u.createdAt >= :since')
            ->setParameter('failed', AiUsage::STATUS_FAILED)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return $oldest !== null ? new \DateTimeImmutable((string) $oldest) : null;
    }

    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('u')
            ->delete()
            ->where('u.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    /** @return AiUsage[] */
    public function findLatest(int $limit): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
