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

    /**
     * Montants par jour de la semaine courante (lundi 00:00:00 → dimanche 23:59:59), un cumul par JOUR.
     * Correctif 26/10/2026 (lot P2-a, point B) : le regroupement se faisait par horodatage exact (deux paiements du même jour
     * n'en comptaient qu'un) et la semaine commençait le lundi à l'heure courante.
     *
     * @return array<string, float> 'Y-m-d' → total
     */
    public function getDailyPaymentsForCurrentWeek(string $paymentType, ?int $orderSource = null): array
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week')->setTime(0, 0, 0);
        $endOfWeek = (clone $startOfWeek)->modify('+6 days')->setTime(23, 59, 59);

        $qb = $this->createQueryBuilder('p')
            ->select('p.paymentDate as date, p.amount as amount')
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

        $dailyPayments = [];
        $period = new \DatePeriod($startOfWeek, new \DateInterval('P1D'), (clone $endOfWeek)->modify('+1 second'));
        foreach ($period as $date) {
            $dailyPayments[$date->format('Y-m-d')] = 0;
        }
        foreach ($qb->getQuery()->getResult() as $row) {
            $date = $row['date'];
            $day = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10);
            if (array_key_exists($day, $dailyPayments)) {
                $dailyPayments[$day] = (float) $dailyPayments[$day] + (float) $row['amount'];
            }
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