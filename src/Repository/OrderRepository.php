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


     /**
     * Calcule le total des montants des transporteurs par mois pour une année donnée.
     *
     * @param int $year
     * @return array
     */
    public function getTotalCarrierByMonth(int $year): array
    {
        $result = [];

        // Parcourir chaque mois de l'année
        for ($month = 1; $month <= 12; $month++) {
            // Créer les dates de début et de fin du mois
            $startDate = new DateTime("$year-$month-01");
            $endDate = (clone $startDate)->modify('last day of this month');

            // Effectuer la requête pour le mois actuel
            $total = $this->createQueryBuilder('o')
                ->select('SUM(c.price) as total')
                ->join('o.carrier', 'c')
                ->where('o.orderDate BETWEEN :start AND :end')
                ->setParameter('start', $startDate)
                ->setParameter('end', $endDate)
                ->getQuery()
                ->getSingleScalarResult();

            // Stocker le résultat pour le mois
            $result[$month] = (float)$total;
        }

        return $result;
    }
}
