<?php

namespace App\Repository;

use App\Entity\Order;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base
use DateTime;

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<Order>
 */
class OrderRepository extends EntityRepository
{
    /**
     * SUPPRIMÉ : Le constructeur n'est plus nécessaire.
     */
    // public function __construct(ManagerRegistry $registry) { ... }


    /**
     * INCHANGÉES : Toutes vos méthodes personnalisées ci-dessous fonctionneront parfaitement.
     * La méthode `$this->createQueryBuilder()` utilisera automatiquement l'EntityManager
     * du tenant qui a été fourni lors de la création de ce repository.
     */

    public function getTotalRevenueBetweenDates(DateTime $startDate, DateTime $endDate, ?int $orderSource = null): float
    {
        $qb = $this->createQueryBuilder('o')
            ->select('SUM(o.totalAmount) as totalRevenue')
            ->where('o.orderDate BETWEEN :startDate AND :endDate')
            ->setParameter('startDate', $startDate->format('Y-m-d 00:00:00'))
            ->setParameter('endDate', $endDate->format('Y-m-d 23:59:59'));
    
        if ($orderSource !== null) {
            $qb->andWhere('o.orderSource = :orderSource')
               ->setParameter('orderSource', $orderSource);
        }
    
        $result = $qb->getQuery()->getSingleScalarResult();
    
        return (float) ($result ?? 0.0);
    }
    
    public function getOrderCountBetweenDates(DateTime $startDate, DateTime $endDate, ?int $orderSource = null): int
    {
        $qb = $this->createQueryBuilder('o')
            ->select('COUNT(o.id) as orderCount')
            ->where('o.orderDate BETWEEN :startDate AND :endDate')
            ->setParameter('startDate', $startDate->format('Y-m-d 00:00:00'))
        ->setParameter('endDate', $endDate->format('Y-m-d 23:59:59'));

        if ($orderSource !== null) {
            $qb->andWhere('o.orderSource = :orderSource')
                ->setParameter('orderSource', $orderSource);
        }

        $result = $qb->getQuery()->getSingleScalarResult();

        return (int) $result;
    }

    public function getOrderCountBetweenDatesAndFilters(DateTime $startDate, DateTime $endDate, int $typeId, int $statusId, ?int $orderSource = null): int
    {
        $qb = $this->createQueryBuilder('o')
        ->select('COUNT(o.id) as orderCount')
        ->where('o.statusUpdatedAt >= :startDate')
        ->andWhere('o.statusUpdatedAt <= :endDate')
        ->andWhere('o.orderType = :typeId')
        ->andWhere('o.status = :statusId')
        ->setParameter('startDate', $startDate->setTime(0, 0, 0))
        ->setParameter('endDate', $endDate->setTime(23, 59, 59))
        ->setParameter('typeId', $typeId)
        ->setParameter('statusId', $statusId);

        if ($orderSource !== null) {
            $qb->andWhere('o.orderSource = :orderSource')
                ->setParameter('orderSource', $orderSource);
        }

        $result = $qb->getQuery()->getSingleScalarResult();

        return (int) $result;
    }

    public function getAverageOrderValueBetweenDates(DateTime $startDate, DateTime $endDate, ?int $orderSource = null): float
    {
        $qb = $this->createQueryBuilder('o')
            ->select('AVG(o.totalAmount) as averageOrderValue')
            ->where('o.orderDate BETWEEN :startDate AND :endDate')
            ->andWhere('o.totalAmount > 0')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate);
            
            if ($orderSource !== null) {
                $qb->andWhere('o.orderSource = :orderSource')
                    ->setParameter('orderSource', $orderSource);
            }
            
        $result = $qb->getQuery()->getSingleScalarResult();

        return (float) ($result ?? 0.0);
    }
    
    public function getTotalCarrierByMonth(int $year): array
    {
        $result = [];

        for ($month = 1; $month <= 12; $month++) {
            $startDate = new DateTime("$year-$month-01");
            $endDate = (clone $startDate)->modify('last day of this month');

            $total = $this->createQueryBuilder('o')
                ->select('SUM(c.price) as total')
                ->join('o.carrier', 'c')
                ->where('o.orderDate BETWEEN :start AND :end')
                ->setParameter('start', $startDate->format('Y-m-d 00:00:00'))
                ->setParameter('end', $endDate->format('Y-m-d 23:59:59'))
                ->getQuery()
                ->getSingleScalarResult();

            $result[$month] = (float) ($total ?? 0.0);
        }

        return $result;
    }

    public function findWithDetailsByUser(int $userId): array
    {
        return $this->createDetailsQueryBuilder()
            ->andWhere('o.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();
    }

    public function findWithDetailsBySource(int $orderSourceId, ?DateTime $since = null): array
    {
        $qb = $this->createDetailsQueryBuilder()
            ->andWhere('o.orderSource = :orderSourceId')
            ->setParameter('orderSourceId', $orderSourceId);

        if ($since) {
            $qb->andWhere('o.orderDate >= :since')
               ->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    private function createDetailsQueryBuilder()
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.orderItems', 'oi')
            ->leftJoin('o.shippingAdress', 'sa')
            ->leftJoin('o.orderSource', 'os')
            ->leftJoin('o.status', 'st')
            ->leftJoin('st.translations', 'stt')
            ->leftJoin('oi.productVariant', 'pv')
            ->leftJoin('pv.product', 'p')
            ->leftJoin('p.saleUnit', 'psu')
            ->leftJoin('pv.color', 'pvc')
            ->leftJoin('pv.size', 'pvs')
            ->leftJoin('pv.optionValues', 'pvov')
            ->leftJoin('pvov.productOption', 'pvpo')
            ->leftJoin('oi.booking', 'bk')
            ->addSelect('oi', 'sa', 'os', 'st', 'stt', 'pv', 'p', 'psu', 'pvc', 'pvs', 'pvov', 'pvpo', 'bk');
    }
}