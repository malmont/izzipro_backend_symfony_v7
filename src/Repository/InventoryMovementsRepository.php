<?php

namespace App\Repository;

use App\Entity\InventoryMovements;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use DateTime;

/**
 * @extends ServiceEntityRepository<InventoryMovements>
 */
class InventoryMovementsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryMovements::class);
    }

    /**
     * Trouve les mouvements de stock entre deux dates.
     *
     * @param DateTime $startDate
     * @param DateTime $endDate
     * @return InventoryMovements[]
     */
    public function findByDateRange(DateTime $startDate, DateTime $endDate): array
    {
        return $this->createQueryBuilder('im')
            ->where('im.movementDate BETWEEN :startDate AND :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('im.movementDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les mouvements de stock par type de mouvement entre deux dates.
     *
     * @param DateTime $startDate
     * @param DateTime $endDate
     * @param string $movementTypeName
     * @return InventoryMovements[]
     */
    public function findByDateRangeAndMovementType(DateTime $startDate, DateTime $endDate, string $movementTypeName): array
    {
        return $this->createQueryBuilder('im')
            ->join('im.movementType', 'mt')
            ->where('im.movementDate BETWEEN :startDate AND :endDate')
            ->andWhere('mt.name = :movementTypeName')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->setParameter('movementTypeName', $movementTypeName)
            ->orderBy('im.movementDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calcule la quantité totale de stock pour un type de mouvement spécifique entre deux dates.
     *
     * @param DateTime $startDate
     * @param DateTime $endDate
     * @param string $movementTypeName
     * @return int
     */
    public function getTotalQuantityByMovementType(DateTime $startDate, DateTime $endDate, string $movementTypeName): int
    {
        return (int) $this->createQueryBuilder('im')
            ->select('SUM(im.quantity)')
            ->join('im.movementType', 'mt')
            ->where('im.movementDate BETWEEN :startDate AND :endDate')
            ->andWhere('mt.name = :movementTypeName')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->setParameter('movementTypeName', $movementTypeName)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Calcule la quantité de stock à une date spécifique pour un produit donné.
     *
     * @param Product $product
     * @param DateTime $date
     * @return int
     */
    public function getStockQuantityAtDate(Product $product, DateTime $date): int
    {
        return (int) $this->createQueryBuilder('im')
            ->select('SUM(im.quantity) as stockQuantity')
            ->join('im.productVariant', 'pv')
            ->where('pv.product = :product')
            ->andWhere('im.movementDate <= :date')
            ->setParameter('product', $product)
            ->setParameter('date', $date)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Calcule la quantité totale de stock pour chaque type de mouvement à une date donnée.
     *
     * @param Product $product
     * @param DateTime $date
     * @return array
     */
    public function getTotalQuantityByMovementTypeAtDate(Product $product, DateTime $date): array
    {
        $qb = $this->createQueryBuilder('im')
            ->select('mt.name as movementType, SUM(im.quantity) as totalQuantity')
            ->join('im.movementType', 'mt')
            ->join('im.productVariant', 'pv')
            ->where('pv.product = :product')
            ->andWhere('im.movementDate <= :date')
            ->setParameter('product', $product)
            ->setParameter('date', $date)
            ->groupBy('mt.name');

        return $qb->getQuery()->getArrayResult();
    }
}
