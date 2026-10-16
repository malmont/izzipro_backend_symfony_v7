<?php

namespace App\MemoiresVivantes\Repository;

use App\Entity\User;
use App\MemoiresVivantes\Entity\AiUsage;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<AiUsage> */
class AiUsageRepository extends EntityRepository
{
    /** Appels aboutis d'un utilisateur, d'un type, dans une fenêtre [from, to[ (dates UTC) */
    public function countFor(User $user, string $kind, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.user = :user')->andWhere('u.kind = :kind')
            ->andWhere('u.createdAt >= :from')->andWhere('u.createdAt < :to')
            ->setParameter('user', $user)->setParameter('kind', $kind)
            ->setParameter('from', $from)->setParameter('to', $to)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Consommation par utilisateur dans une fenêtre : liste triée par e-mail
     *
     * @return list<array{userId: int, email: string, name: string, transcriptions: int, improvements: int}>
     */
    public function usageByUser(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->createQueryBuilder('u')
            ->select('usr.id AS userId, usr.email AS email, usr.firstname AS firstname, usr.lastname AS lastname, u.kind AS kind, COUNT(u.id) AS total')
            ->join('u.user', 'usr')
            ->where('u.createdAt >= :from')->andWhere('u.createdAt < :to')->andWhere('u.kind IN (:kinds)')
            ->setParameter('from', $from)->setParameter('to', $to)->setParameter('kinds', AiUsage::QUOTA_KINDS)
            ->groupBy('usr.id, usr.email, usr.firstname, usr.lastname, u.kind')
            ->orderBy('usr.email', 'ASC')
            ->getQuery()->getArrayResult();
        $byUser = [];
        foreach ($rows as $row) {
            $id = (int) $row['userId'];
            $byUser[$id] ??= ['userId' => $id, 'email' => (string) $row['email'], 'name' => trim((string) $row['firstname'] . ' ' . (string) $row['lastname']), 'transcriptions' => 0, 'improvements' => 0];
            $byUser[$id][$row['kind'] === AiUsage::KIND_TRANSCRIPTION ? 'transcriptions' : 'improvements'] = (int) $row['total'];
        }

        return array_values($byUser);
    }
}
