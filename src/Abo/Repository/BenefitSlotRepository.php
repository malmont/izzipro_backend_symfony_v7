<?php

namespace App\Abo\Repository;

use App\Abo\Entity\BenefitSlot;
use Doctrine\ORM\EntityRepository;

/** Créneaux datés (lot ABO-2) ; obtenu par TenantEntityManagerProvider */
class BenefitSlotRepository extends EntityRepository
{
    /** @return list<BenefitSlot> les créneaux d'une prestation (ou de toutes) entre deux instants UTC, du plus proche au plus lointain */
    public function between(?int $benefitId, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $openOnly): array
    {
        $qb = $this->createQueryBuilder('s')->join('s.benefit', 'b')->addSelect('b')->where('s.startsAt >= :from')->andWhere('s.startsAt < :to')
            ->setParameter('from', $from)->setParameter('to', $to)->orderBy('s.startsAt', 'ASC')->addOrderBy('s.id', 'ASC')->setMaxResults(500);
        if ($benefitId !== null) {
            $qb->andWhere('s.benefit = :b')->setParameter('b', $benefitId);
        }
        if ($openOnly) {
            $qb->andWhere('s.status = :open')->setParameter('open', BenefitSlot::STATUS_OPEN);
        }

        return $qb->getQuery()->getResult();
    }
}
