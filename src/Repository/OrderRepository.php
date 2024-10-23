<?php

namespace App\Repository;

use App\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use DateTime;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * Calcule le chiffre d'affaires total entre deux dates.
     *
     * @param DateTime $startDate
     * @param DateTime $endDate
     * @return float
     */
    public function getTotalRevenueBetweenDates(DateTime $startDate, DateTime $endDate): float
    {
        $qb = $this->createQueryBuilder('o')
            ->select('SUM(o.totalAmount) as totalRevenue')
            ->where('o.orderDate BETWEEN :startDate AND :endDate')
            ->setParameter('startDate', $startDate->format('Y-m-d'))
            ->setParameter('endDate', $endDate->format('Y-m-d'));

        $result = $qb->getQuery()->getSingleScalarResult();

        return (float) $result;
    }

    /**
     * Compte le nombre de commandes entre deux dates.
     *
     * @param DateTime $startDate
     * @param DateTime $endDate
     * @return int
     */
    public function getOrderCountBetweenDates(DateTime $startDate, DateTime $endDate): int
    {
        $qb = $this->createQueryBuilder('o')
            ->select('COUNT(o.id) as orderCount')
            ->where('o.orderDate BETWEEN :startDate AND :endDate')
            ->setParameter('startDate', $startDate->format('Y-m-d 00:00:00'))
            ->setParameter('endDate', $endDate->format('Y-m-d 23:59:59'));

        $result = $qb->getQuery()->getSingleScalarResult();

        return (int) $result;
    }

    /**
     * Calcule le panier moyen entre deux dates.
     *
     * @param DateTime $startDate
     * @param DateTime $endDate
     * @return float
     */
    public function getAverageOrderValueBetweenDates(DateTime $startDate, DateTime $endDate): float
    {
        $qb = $this->createQueryBuilder('o')
            ->select('AVG(o.totalAmount) as averageOrderValue')
            ->where('o.orderDate BETWEEN :startDate AND :endDate')
            ->setParameter('startDate', $startDate->format('Y-m-d 00:00:00'))
            ->setParameter('endDate', $endDate->format('Y-m-d 23:59:59'));

        $result = $qb->getQuery()->getSingleScalarResult();

        return (float) $result;
    }
}
