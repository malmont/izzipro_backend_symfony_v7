<?php

namespace App\Repository;

use App\Entity\Payments;
use Doctrine\ORM\EntityRepository; // MODIFIÉ : On utilise le repository de base
use DateTime;

/**
 * N'est plus un service Symfony.
 * @extends EntityRepository<Payments>
 */
class PaymentsRepository extends EntityRepository
{
    /**
     * SUPPRIMÉ : Le constructeur n'est plus nécessaire.
     */
    // public function __construct(ManagerRegistry $registry) { ... }

    /**
     * INCHANGÉES : Toutes vos méthodes personnalisées fonctionneront parfaitement car
     * $this->createQueryBuilder() utilisera l'EntityManager du tenant.
     */
    public function getTotalPaymentsForCurrentWeek(string $paymentType, ?int $orderSource = null): float
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week');
        $endOfWeek = (clone $startOfWeek)->modify('+6 days');

        $qb = $this->createQueryBuilder('p')
            ->select('SUM(p.amount)')
            ->join('p.paymentType', 'pt')
            ->join('p.orderPayment', 'o') // Jointure avec Order
            ->where('pt.name = :type')
            ->andWhere('p.paymentDate BETWEEN :start AND :end')
            ->setParameter('type', $paymentType)
            ->setParameter('start', $startOfWeek)
            ->setParameter('end', $endOfWeek);

        if ($orderSource !== null) {
            $qb->andWhere('o.orderSource = :orderSource')
               ->setParameter('orderSource', $orderSource);
        }

        $result = $qb->getQuery()->getSingleScalarResult();

        return (float) ($result ?? 0.0);
    }

    public function getDailyPaymentsForCurrentWeek(string $paymentType, ?int $orderSource = null): array
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week');
        $endOfWeek = (clone $startOfWeek)->modify('+6 days');

        $qb = $this->createQueryBuilder('p')
            ->select('p.paymentDate as date, SUM(p.amount) as total')
            ->join('p.paymentType', 'pt')
            ->join('p.orderPayment', 'o') // Jointure avec Order
            ->where('pt.name = :type')
            ->andWhere('p.paymentDate BETWEEN :start AND :end')
            ->setParameter('type', $paymentType)
            ->setParameter('start', $startOfWeek)
            ->setParameter('end', $endOfWeek)
            ->groupBy('p.paymentDate');

        if ($orderSource !== null) {
            $qb->andWhere('o.orderSource = :orderSource')
               ->setParameter('orderSource', $orderSource);
        }

        $results = $qb->getQuery()->getResult();
        $dailyPayments = [];
        $period = new \DatePeriod($startOfWeek, new \DateInterval('P1D'), $endOfWeek->modify('+1 day'));

        foreach ($period as $date) {
            $formattedDate = $date->format('Y-m-d');
            $dailyPayments[$formattedDate] = 0;
        }

        foreach ($results as $result) {
            $date = $result['date'];
            $formattedDate = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;
            $dailyPayments[$formattedDate] = (float) $result['total'];
        }

        return $dailyPayments;
    }

    public function getTotalPaymentsForCurrentMonth(string $paymentType, ?int $orderSource = null): float
    {
        $now = new DateTime();
        $startOfMonth = (clone $now)->modify('first day of this month');
        $endOfMonth = (clone $now)->modify('last day of this month');

        return $this->getTotalPaymentsForDateRange($paymentType, $startOfMonth, $endOfMonth, $orderSource);
    }

    public function getTotalPaymentsForCurrentYear(string $paymentType, ?int $orderSource = null): float
    {
        $now = new DateTime();
        $startOfYear = (clone $now)->modify('first day of January');
        $endOfYear = (clone $now)->modify('last day of December');

        return $this->getTotalPaymentsForDateRange($paymentType, $startOfYear, $endOfYear, $orderSource);
    }

    private function getTotalPaymentsForDateRange(string $paymentType, DateTime $startDate, DateTime $endDate, ?int $orderSource = null): float
    {
        $qb = $this->createQueryBuilder('p')
            ->select('SUM(p.amount)')
            ->join('p.paymentType', 'pt')
            ->join('p.orderPayment', 'o') // Jointure avec Order
            ->where('pt.name = :type')
            ->andWhere('p.paymentDate BETWEEN :start AND :end')
            ->setParameter('type', $paymentType)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate);

        if ($orderSource !== null) {
            $qb->andWhere('o.orderSource = :orderSource')
               ->setParameter('orderSource', $orderSource);
        }

        $result = $qb->getQuery()->getSingleScalarResult();

        return (float) ($result ?? 0.0);
    }
}