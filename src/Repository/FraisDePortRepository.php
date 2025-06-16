<?php

namespace App\Repository;

use App\Entity\FraisDePort;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base
use DateTime;

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<FraisDePort>
 */
class FraisDePortRepository extends EntityRepository
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

        return (float) $this->createQueryBuilder('f')
            ->select('SUM(f.price)')
            ->join('f.commande', 'c')
            ->where('c.date BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', 'endDate')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getTotalFraisByMonth(int $year, int $month): float
    {
        $startDate = new DateTime("$year-$month-01");
        $endDate = (clone $startDate)->modify('last day of this month');

        return (float) $this->createQueryBuilder('f')
            ->select('SUM(f.price)')
            ->join('f.commande', 'c')
            ->where('c.date BETWEEN :start AND :end')
            ->setParameter('start', 'startDate')
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getTotalFraisByDay(DateTime $date): float
    {
        $startOfDay = (clone $date)->setTime(0, 0);
        $endOfDay = (clone $date)->setTime(23, 59, 59);

        return (float) $this->createQueryBuilder('f')
            ->select('SUM(f.price)')
            ->join('f.commande', 'c')
            ->where('c.date BETWEEN :start AND :end')
            ->setParameter('start', $startOfDay)
            ->setParameter('end', $endOfDay)
            ->getQuery()
            ->getSingleScalarResult();
    }
}