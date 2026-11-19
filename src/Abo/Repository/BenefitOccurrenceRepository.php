<?php

namespace App\Abo\Repository;

use App\Abo\Entity\BenefitOccurrence;
use App\Entity\Subscription;
use Doctrine\ORM\EntityRepository;

/** Droits à profiter (lot ABO-1) ; obtenu par TenantEntityManagerProvider */
class BenefitOccurrenceRepository extends EntityRepository
{
    /** @return list<BenefitOccurrence> */
    public function forSubscription(Subscription $s): array
    {
        return $this->createQueryBuilder('o')->join('o.benefit', 'b')->addSelect('b')->where('o.subscription = :s')->setParameter('s', $s)
            ->orderBy('o.validUntil', 'ASC')->addOrderBy('o.id', 'ASC')->getQuery()->getResult();
    }

    /** @return list<BenefitOccurrence> droits disponibles arrivés à échéance, tous abonnements du site */
    public function dueAvailable(\DateTimeImmutable $now, int $limit = 1000): array
    {
        return $this->createQueryBuilder('o')->join('o.subscription', 's')->addSelect('s')->where('o.status = :st')->andWhere('o.validUntil <= :now')
            ->setParameter('st', BenefitOccurrence::STATUS_AVAILABLE)->setParameter('now', $now)->orderBy('o.id', 'ASC')->setMaxResults($limit)->getQuery()->getResult();
    }
}
