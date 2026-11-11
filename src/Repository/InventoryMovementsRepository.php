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

    /**
     * Quantités par type de mouvement sur [début, fin[ (instants UTC, comme movement_date) ; statistiques B2 (11/11/2026).
     *
     * @return array<int, int> movement_type_id => somme des quantités
     */
    public function totalsByMovementTypeId(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT movement_type_id, COALESCE(SUM(quantity), 0) FROM inventory_movements WHERE movement_date >= :from AND movement_date < :to AND movement_type_id IS NOT NULL GROUP BY movement_type_id',
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')]
        );

        return array_map('intval', $rows);
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