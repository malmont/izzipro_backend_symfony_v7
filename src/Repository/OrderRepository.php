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
    public function getTotalRevenueBetweenDates(DateTime $startDate, DateTime $endDate, ?int $orderSource = null): float
    {
        $qb = $this->createQueryBuilder('o')
            ->select('SUM(o.totalAmount) as totalRevenue')
            ->where('o.orderDate BETWEEN :startDate AND :endDate')
            ->setParameter('startDate', $startDate->format('Y-m-d 00:00:00'))
            ->setParameter('endDate', $endDate->format('Y-m-d 23:59:59'));
    
        // Appliquer le filtre de source de commande, si spécifié
        if ($orderSource !== null) {
            $qb->andWhere('o.orderSource = :orderSource')
               ->setParameter('orderSource', $orderSource);
        }
    
        $result = $qb->getQuery()->getSingleScalarResult();
    
        return (float) ($result ?? 0.0);
    }
    

    /**
     * Compte le nombre de commandes entre deux dates.
     *
     * @param DateTime $startDate
     * @param DateTime $endDate
     * @return int
     */
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


    /**
     * Compte le nombre de commandes entre deux dates, avec des filtres pour le type et le statut.
     *
     * @param DateTime $startDate
     * @param DateTime $endDate
     * @param int $typeId
     * @param int $statusId
     * @return int
     */
    public function getOrderCountBetweenDatesAndFilters(DateTime $startDate, DateTime $endDate, int $typeId, int $statusId, ?int $orderSource = null): int
        {
            $qb = $this->createQueryBuilder('o')
            ->select('COUNT(o.id) as orderCount')
            ->where('o.statusUpdatedAt >= :startDate')
            ->andWhere('o.statusUpdatedAt <= :endDate')
            ->andWhere('o.orderType = :typeId')
            ->andWhere('o.status = :statusId')
            ->setParameter('startDate', $startDate->setTime(0, 0, 0)) // Début de la journée
            ->setParameter('endDate', $endDate->setTime(23, 59, 59)) // Fin de la journée
            ->setParameter('typeId', $typeId)
            ->setParameter('statusId', $statusId);

            if ($orderSource !== null) {
                $qb->andWhere('o.orderSource = :orderSource')
                    ->setParameter('orderSource', $orderSource);
            }

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
    public function getAverageOrderValueBetweenDates(DateTime $startDate, DateTime $endDate, ?int $orderSource = null): float
    {
        $qb = $this->createQueryBuilder('o')
            ->select('AVG(o.totalAmount) as averageOrderValue')
            ->where('o.orderDate BETWEEN :startDate AND :endDate')
            ->andWhere('o.totalAmount > 0') // Exclure les montants négatifs si nécessaire
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate);
            
            if ($orderSource !== null) {
                $qb->andWhere('o.orderSource = :orderSource')
                    ->setParameter('orderSource', $orderSource);
            }
            
        $result = $qb->getQuery()->getSingleScalarResult();

        return (float) ($result ?? 0.0);
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
                ->setParameter('start', $startDate->format('Y-m-d 00:00:00'))
                ->setParameter('end', $endDate->format('Y-m-d 23:59:59'))
                ->getQuery()
                ->getSingleScalarResult();

            // Stocker le résultat pour le mois
            $result[$month] = (float) ($total ?? 0.0);
        }

        return $result;
    }
}
