<?php

namespace App\Repository;

use App\Entity\NoteDeFrais;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use DateTime;

/**
 * @extends ServiceEntityRepository<NoteDeFrais>
 */
class NoteDeFraisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NoteDeFrais::class);
    }

    /**
     * Calcule le total des notes de frais pour une année donnée.
     *
     * @param int $year
     * @return float
     */
    public function getTotalFraisByYear(int $year): float
    {
        $startDate = new DateTime("$year-01-01");
        $endDate = new DateTime("$year-12-31");

        return (float) $this->createQueryBuilder('n')
            ->select('SUM(n.montant)')
            ->where('n.date >= :start')
            ->andWhere('n.date <= :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Calcule le total des notes de frais pour un mois donné d'une année spécifique.
     *
     * @param int $year
     * @param int $month
     * @return float
     */
    public function getTotalFraisByMonth(int $year, int $month): float
    {
        $startDate = new DateTime("$year-$month-01");
        $endDate = (clone $startDate)->modify('last day of this month');

        return (float) $this->createQueryBuilder('n')
            ->select('SUM(n.montant)')
            ->where('n.date >= :start')
            ->andWhere('n.date <= :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Calcule le total des notes de frais pour une date spécifique.
     *
     * @param DateTime $date
     * @return float
     */
    public function getTotalFraisByDay(DateTime $date): float
    {
        $startOfDay = (clone $date)->setTime(0, 0);
        $endOfDay = (clone $date)->setTime(23, 59, 59);

        return (float) $this->createQueryBuilder('n')
            ->select('SUM(n.montant)')
            ->where('n.date >= :start')
            ->andWhere('n.date <= :end')
            ->setParameter('start', $startOfDay)
            ->setParameter('end', $endOfDay)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
