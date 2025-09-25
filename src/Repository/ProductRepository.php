<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\ORM\EntityRepository;


class ProductRepository extends EntityRepository
{
    public function save(Product $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Product $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findWithSearch($search)
    {
        $query = $this->createQueryBuilder('p');
            
        if ($search->getMinPrice()) {
            $query->andWhere('p.price > :minPrice')
                  ->setParameter('minPrice', $search->getMinPrice() * 100);
        }
        if ($search->getMaxPrice()) {
            $query->andWhere('p.price < :maxPrice')
                  ->setParameter('maxPrice', $search->getMaxPrice() * 100);
        }
        if ($search->getCategories()) {
            $query->join('p.category', 'c')
                  ->andWhere('c.id IN (:categories)')
                  ->setParameter('categories', $search->getCategories());
        }
        if ($search->getTags()) {
            $query->andWhere('p.tags LIKE :val')
                  ->setParameter('val', "%" . $search->getTags() . "%");
        }

        return $query->getQuery()->getResult();
    }

    public function calculateCurrentStockValue(): float
    {
        $qb = $this->createQueryBuilder('p')
            ->select('SUM(p.purchasePrice * p.coefficientMultiplier * p.quantity) as stockValue')
            ->where('p.purchasePrice IS NOT NULL')
            ->andWhere('p.coefficientMultiplier IS NOT NULL');

        $result = $qb->getQuery()->getSingleScalarResult();

        return (float) $result;
    }
    public function findByCategoryAndFilters(
        string $locale,
        ?array $categoryIds,
        ?string $keyword,
        int $page,
        int $pageSize,
        ?string $barcode,
        ?bool $isWeb,
        ?bool $isPos
    ): array {
        $queryBuilder = $this->createQueryBuilder('p')
            
            ->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')
            ->addSelect('t')
            ->setParameter('locale', $locale);

        if ($categoryIds) {
            $queryBuilder->join('p.category', 'c')
                         ->andWhere('c.id IN (:categoryIds)')
                         ->setParameter('categoryIds', $categoryIds);
        }

        if ($keyword) {
            $queryBuilder->andWhere('(p.name LIKE :keyword OR p.description LIKE :keyword OR t.name LIKE :keyword OR t.description LIKE :keyword)')
                         ->setParameter('keyword', '%' . $keyword . '%');
        }

        if ($barcode) {
            $queryBuilder->andWhere('p.barcode = :barcode')
                         ->setParameter('barcode', $barcode);
        }
        
        if ($isWeb !== null) {
            $queryBuilder->andWhere('p.isWeb = :isWeb')
                         ->setParameter('isWeb', $isWeb);
        }

        if ($isPos !== null) {
            $queryBuilder->andWhere('p.isPos = :isPos')
                         ->setParameter('isPos', $isPos);
        }

        $queryBuilder->setFirstResult(($page - 1) * $pageSize)
                     ->setMaxResults($pageSize);

        return $queryBuilder->getQuery()->getResult();
    }
    public function findTranslatedByCriteria(string $locale, array $criteria): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')
            ->addSelect('t')
            ->setParameter('locale', $locale);

        foreach ($criteria as $field => $value) {
            $queryBuilder->andWhere("p.{$field} = :{$field}")
                         ->setParameter($field, $value);
        }

        return $queryBuilder->getQuery()->getResult();
    }
    public function findByIdAndLocale(int $id, string $locale): ?Product
    {
        return $this->createQueryBuilder('p')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')
            ->addSelect('t')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
