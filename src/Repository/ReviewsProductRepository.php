<?php

namespace App\Repository;

use App\Entity\Product;
use App\Entity\ReviewsProduct;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<ReviewsProduct> */
class ReviewsProductRepository extends EntityRepository
{
    public const SORTS = ['recent', 'highest', 'lowest'];

    public function save(ReviewsProduct $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ReviewsProduct $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByProductAndUser(Product $product, User $user): ?ReviewsProduct
    {
        return $this->findOneBy(['productReviews' => $product, 'userReview' => $user]);
    }

    /**
     * Avis publiés d'un produit, page par page.
     *
     * @return array{items: list<ReviewsProduct>, total: int}
     */
    public function findPublished(Product $product, int $page, int $perPage, string $sort, ?int $rating): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.productReviews = :product')->andWhere('r.status = :status')
            ->setParameter('product', $product)->setParameter('status', ReviewsProduct::STATUS_APPROVED);
        if ($rating !== null) {
            $qb->andWhere('r.rating = :rating')->setParameter('rating', $rating);
        }
        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        match ($sort) {
            'highest' => $qb->orderBy('r.rating', 'DESC')->addOrderBy('r.publishedAt', 'DESC'),
            'lowest' => $qb->orderBy('r.rating', 'ASC')->addOrderBy('r.publishedAt', 'DESC'),
            default => $qb->orderBy('r.publishedAt', 'DESC'),
        };
        $qb->addOrderBy('r.id', 'DESC')->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);

        return ['items' => $qb->getQuery()->getResult(), 'total' => $total];
    }

    /** @return array{average: ?float, count: int, distribution: array<int, int>} répartition 5 → 1 des avis publiés */
    public function summary(Product $product): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.rating AS rating, COUNT(r.id) AS n')
            ->where('r.productReviews = :product')->andWhere('r.status = :status')
            ->setParameter('product', $product)->setParameter('status', ReviewsProduct::STATUS_APPROVED)
            ->groupBy('r.rating')->getQuery()->getArrayResult();
        $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $sum = 0;
        $count = 0;
        foreach ($rows as $row) {
            $value = (int) $row['rating'];
            if (isset($distribution[$value])) {
                $distribution[$value] = (int) $row['n'];
                $sum += $value * (int) $row['n'];
                $count += (int) $row['n'];
            }
        }

        return ['average' => $count > 0 ? round($sum / $count, 2) : null, 'count' => $count, 'distribution' => $distribution];
    }

    /** @return list<ReviewsProduct> avis d'un client, du plus récent au plus ancien */
    public function findByUser(User $user): array
    {
        return $this->findBy(['userReview' => $user], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function countPending(): int
    {
        return $this->count(['status' => ReviewsProduct::STATUS_PENDING]);
    }
}
