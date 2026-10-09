<?php

namespace App\Repository;

use App\Entity\SubscriptionPlan;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<SubscriptionPlan>
 */
class SubscriptionPlanRepository extends EntityRepository
{
    /** @return SubscriptionPlan[] formules actives d'un produit (ou de tous), les moins chères d'abord */
    public function findActive(?int $productId): array
    {
        $qb = $this->createQueryBuilder('p')->andWhere('p.active = true')->orderBy('p.price', 'ASC')->addOrderBy('p.id', 'ASC');
        if ($productId !== null) {
            $qb->andWhere('p.product = :product')->setParameter('product', $productId);
        }

        return $qb->getQuery()->getResult();
    }
}
