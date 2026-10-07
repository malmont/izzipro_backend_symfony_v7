<?php

namespace App\Repository;

use App\Entity\ContentAuditLog;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<ContentAuditLog>
 */
class ContentAuditLogRepository extends EntityRepository
{
    /** @return array{0: ContentAuditLog[], 1: int} page demandée (plus récentes d'abord), total */
    public function search(?string $resource, ?string $resourceId, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('l');
        if ($resource !== null) {
            $qb->andWhere('l.resource = :resource')->setParameter('resource', $resource);
        }
        if ($resourceId !== null) {
            $qb->andWhere('l.resourceId = :resourceId')->setParameter('resourceId', $resourceId);
        }
        $total = (int) (clone $qb)->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->orderBy('l.createdAt', 'DESC')->addOrderBy('l.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        return [$items, $total];
    }

    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('l')->delete()->where('l.createdAt < :before')->setParameter('before', $before)->getQuery()->execute();
    }
}
