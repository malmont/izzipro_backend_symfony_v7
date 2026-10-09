<?php

namespace App\Repository;

use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<Subscription>
 */
class SubscriptionRepository extends EntityRepository
{
    /** @return Subscription[] abonnements d'un client, du plus récent au plus ancien */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('s')->andWhere('s.user = :user')->setParameter('user', $user)
            ->orderBy('s.createdAt', 'DESC')->addOrderBy('s.id', 'DESC')->getQuery()->getResult();
    }

    /** @return Subscription[] abonnements non résiliés d'un client à un produit (toutes formules) */
    public function findOpenForProduct(User $user, \App\Entity\Product $product): array
    {
        return $this->createQueryBuilder('s')->join('s.plan', 'p')
            ->andWhere('s.user = :user')->andWhere('p.product = :product')->andWhere('s.status <> :canceled')
            ->setParameter('user', $user)->setParameter('product', $product)->setParameter('canceled', Subscription::STATUS_CANCELED)
            ->orderBy('s.id', 'DESC')->getQuery()->getResult();
    }

    public function findOneByStripeId(string $stripeSubscriptionId): ?Subscription
    {
        return $this->findOneBy(['stripeSubscriptionId' => $stripeSubscriptionId]);
    }
}
