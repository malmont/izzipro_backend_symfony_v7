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
        // Sans produit : toutes les formules actives de la boutique (grille de formules), produits encore vendus par abonnement
        $qb = $this->createQueryBuilder('p')->join('p.product', 'pr')->andWhere('p.active = true')->andWhere('pr.subscriptionEnabled = true')
            ->orderBy('pr.id', 'ASC')->addOrderBy('p.price', 'ASC')->addOrderBy('p.id', 'ASC');
        if ($productId !== null) {
            $qb->andWhere('p.product = :product')->setParameter('product', $productId);
        }

        return $qb->getQuery()->getResult();
    }

    /** Une seule formule mise en avant par produit : les autres formules du produit sont décochées */
    public function keepOnlyHighlighted(\App\Entity\SubscriptionPlan $plan): void
    {
        if (!$plan->isHighlighted() || $plan->getProduct() === null || $plan->getId() === null) {
            return;
        }
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE subscription_plan SET highlighted = false WHERE product_id = ? AND id <> ? AND highlighted = true',
            [$plan->getProduct()->getId(), $plan->getId()]
        );
    }
}
