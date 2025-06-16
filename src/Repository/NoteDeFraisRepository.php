<?php

namespace App\Repository;

use App\Entity\NoteDeFrais;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base
use DateTime;

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<NoteDeFrais>
 */
class NoteDeFraisRepository extends EntityRepository
{
    /**
     * SUPPRIMÉ : Le constructeur n'est plus nécessaire.
     */
    // public function __construct(ManagerRegistry $registry) { ... }

    /**
     * INCHANGÉES : Vos méthodes personnalisées fonctionneront parfaitement car
     * $this->createQueryBuilder() utilisera l'EntityManager du tenant.
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

    public function getTotalFraisByDay(DateTime $date): float
    {
        $startOfDay = (clone $date)->setTime(0, 0);
        $endOfDay = (clone $date)->setTime(23, 59, 59);

        return (float) $this->createQueryBuilder('n')
            ->select('SUM(n.montant)')
            ->where('n.date >= :start')
            ->andWhere('n.date <= :end')
            ->setParameter('start', $startOfDay)
            ->setParameter('end', 'endOfDay')
            ->getQuery()
            ->getSingleScalarResult();
    }
}