<?php

namespace App\Repository;

use App\Entity\AiJob;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<AiJob>
 */
class AiJobRepository extends EntityRepository
{
    /** Tâches terminées avant $before (résultat conservé 1 heure) */
    public function deleteFinishedBefore(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('j')
            ->delete()
            ->where('j.finishedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    /** Tâche en attente ou en cours de cet utilisateur (base du site courant), la plus récente */
    public function findActiveFor(?string $user): ?AiJob
    {
        $qb = $this->createQueryBuilder('j')
            ->where('j.status IN (:active)')
            ->setParameter('active', [AiJob::STATUS_PENDING, AiJob::STATUS_RUNNING])
            ->orderBy('j.createdAt', 'DESC')
            ->setMaxResults(1);
        $user === null ? $qb->andWhere('j.user IS NULL') : $qb->andWhere('j.user = :user')->setParameter('user', $user);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Tâches bloquées : en attente depuis $pendingBefore (worker arrêté) ou en cours depuis $runningBefore (worker
     * interrompu).
     *
     * @return AiJob[]
     */
    public function findStale(\DateTimeImmutable $pendingBefore, \DateTimeImmutable $runningBefore): array
    {
        return $this->createQueryBuilder('j')
            ->where('(j.status = :pending AND j.createdAt < :pendingBefore) OR (j.status = :running AND j.startedAt < :runningBefore)')
            ->setParameter('pending', AiJob::STATUS_PENDING)
            ->setParameter('running', AiJob::STATUS_RUNNING)
            ->setParameter('pendingBefore', $pendingBefore)
            ->setParameter('runningBefore', $runningBefore)
            ->getQuery()
            ->getResult();
    }
}
