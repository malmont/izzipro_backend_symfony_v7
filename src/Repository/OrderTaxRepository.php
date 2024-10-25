<?php

namespace App\Repository;

use App\Entity\OrderTax;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use DateTime;

/**
 * @extends ServiceEntityRepository<OrderTax>
 */
class OrderTaxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderTax::class);
    }

    /**
     * Calcule le total des taxes pour un mois donné d'une année spécifique.
     *
     * @param int $year
     * @param int $month
     * @return float
     */
    public function getTotalTaxByMonth(int $year, int $month): float
    {
        $startDate = new \DateTime("$year-$month-01");
    $endDate = (clone $startDate)->modify('last day of this month');

    return (float) $this->createQueryBuilder('ot')
        ->select('SUM(ot.amount)')
        ->join('ot.orderTax', 'o')
        ->where('o.orderDate BETWEEN :start AND :end') // Utilisation de 'orderDate' pour la comparaison
        ->setParameter('start', $startDate)
        ->setParameter('end', $endDate)
        ->getQuery()
        ->getSingleScalarResult();
    }
}
