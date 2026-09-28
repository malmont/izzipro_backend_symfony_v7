<?php

namespace App\Repository;

use App\Entity\AiUsage;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<AiUsage>
 */
class AiUsageRepository extends EntityRepository
{
    /** Crédits engagés depuis une date : consommés, ou réservés depuis moins que $reservationTtl secondes */
    public function sumCommittedCredits(\DateTimeImmutable $since, \DateTimeImmutable $reservedAfter): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COALESCE(SUM(u.credits), 0)')
            ->where('u.createdAt >= :since')
            ->andWhere('u.status = :success OR (u.status = :reserved AND u.createdAt >= :reservedAfter)')
            ->setParameter('since', $since)
            ->setParameter('success', AiUsage::STATUS_SUCCESS)
            ->setParameter('reserved', AiUsage::STATUS_RESERVED)
            ->setParameter('reservedAfter', $reservedAfter)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Réservations restées ouvertes (processus interrompu) : crédits libérés */
    public function expireReservationsBefore(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('u')
            ->update()
            ->set('u.status', ':expired')
            ->set('u.completedAt', ':now')
            ->where('u.status = :reserved')
            ->andWhere('u.createdAt < :before')
            ->setParameter('expired', AiUsage::STATUS_EXPIRED)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('reserved', AiUsage::STATUS_RESERVED)
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
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
