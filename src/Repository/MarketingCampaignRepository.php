<?php

namespace App\Repository;

use App\Entity\MarketingCampaign;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<MarketingCampaign> */
class MarketingCampaignRepository extends EntityRepository
{
    /** @return array{0: list<MarketingCampaign>, 1: int} page triée par date de début (récentes d'abord) et total */
    public function search(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?string $channel, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('c');
        if ($from !== null) {
            $qb->andWhere('c.endDate >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('c.startDate <= :to')->setParameter('to', $to);
        }
        if ($channel !== null) {
            $qb->andWhere('c.channel = :channel')->setParameter('channel', $channel);
        }
        $total = (int) (clone $qb)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();

        return [$qb->orderBy('c.startDate', 'DESC')->addOrderBy('c.id', 'DESC')->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult(), $total];
    }

    /** @return list<MarketingCampaign> toutes les campagnes, par date de début (regroupement des chevauchements) */
    public function allByStart(): array
    {
        return $this->findBy([], ['startDate' => 'ASC', 'id' => 'ASC']);
    }
}
