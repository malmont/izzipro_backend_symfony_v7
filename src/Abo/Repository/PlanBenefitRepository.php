<?php

namespace App\Abo\Repository;

use App\Abo\Entity\Benefit;
use App\Abo\Entity\PlanBenefit;
use App\Entity\SubscriptionPlan;
use Doctrine\ORM\EntityRepository;

/** Prestations livrées par les formules (lot ABO-0) */
class PlanBenefitRepository extends EntityRepository
{
    /** @return list<PlanBenefit> */
    public function forPlan(SubscriptionPlan $plan): array
    {
        return $this->createQueryBuilder('pb')->join('pb.benefit', 'b')->addSelect('b')->where('pb.plan = :plan')->setParameter('plan', $plan)
            ->orderBy('pb.position', 'ASC')->addOrderBy('pb.id', 'ASC')->getQuery()->getResult();
    }

    /**
     * Les prestations de plusieurs formules d'un coup (grille publique, lectures de suivi)
     *
     * @param list<int> $planIds
     * @return array<int, list<PlanBenefit>> formule => prestations
     */
    public function forPlans(array $planIds): array
    {
        if ($planIds === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('pb')->join('pb.benefit', 'b')->addSelect('b')->join('pb.plan', 'p')->where('p.id IN (:ids)')->setParameter('ids', $planIds)
            ->orderBy('pb.position', 'ASC')->addOrderBy('pb.id', 'ASC')->getQuery()->getResult();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->getPlan()->getId()][] = $row;
        }

        return $out;
    }

    public function isUsed(Benefit $benefit): bool
    {
        return (int) $this->createQueryBuilder('pb')->select('COUNT(pb.id)')->where('pb.benefit = :b')->setParameter('b', $benefit)->getQuery()->getSingleScalarResult() > 0;
    }
}
