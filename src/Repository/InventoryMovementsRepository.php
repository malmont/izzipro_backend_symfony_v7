<?php

namespace App\Repository;

use App\Entity\InventoryMovements;
use App\Entity\Product;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base
use DateTime;

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<InventoryMovements>
 */
class InventoryMovementsRepository extends EntityRepository
{
    /**
     * SUPPRIMÉ : Le constructeur n'est plus nécessaire.
     */
    // public function __construct(ManagerRegistry $registry) { ... }

    /**
     * INCHANGÉES : Toutes vos méthodes personnalisées fonctionneront parfaitement car
     * $this->createQueryBuilder() utilisera l'EntityManager du tenant.
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