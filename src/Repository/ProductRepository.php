<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<Product>
 */
class ProductRepository extends EntityRepository
{
    /**
     * SUPPRIMÉ : Le constructeur n'est plus nécessaire.
     */
    // public function __construct(ManagerRegistry $registry) { ... }

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
            // SÉCURISÉ : Utilisation d'un paramètre nommé pour éviter l'injection SQL
            $query->andWhere('p.price > :minPrice')
                  ->setParameter('minPrice', $search->getMinPrice() * 100);
        }
        if ($search->getMaxPrice()) {
            // SÉCURISÉ : Utilisation d'un paramètre nommé
            $query->andWhere('p.price < :maxPrice')
                  ->setParameter('maxPrice', $search->getMaxPrice() * 100);
        }
        if ($search->getCategories()) {
            $query->join('p.category', 'c')
                  ->andWhere('c.id IN (:categories)')
                  ->setParameter('categories', $search->getCategories());
        }
        if ($search->getTags()) {
            $query->andWhere('p.tags LIKE :val') // LIKE est souvent écrit avec LIKE
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
}