<?php

namespace App\Repository;

use App\Entity\Payments;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use DateTime;

class PaymentsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payments::class);
    }

    public function getTotalPaymentsForCurrentWeek(string $paymentType): float
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week');
        $endOfWeek = (clone $startOfWeek)->modify('+6 days');

        return (float) $this->createQueryBuilder('p')
            ->select('SUM(p.amount)')
            ->join('p.paymentType', 'pt')
            ->where('pt.name = :type')
            ->andWhere('p.paymentDate BETWEEN :start AND :end')
            ->setParameter('type', $paymentType)
            ->setParameter('start', $startOfWeek)
            ->setParameter('end', $endOfWeek)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getDailyPaymentsForCurrentWeek(string $paymentType): array
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week');
        $endOfWeek = (clone $startOfWeek)->modify('+6 days');

        $results = $this->createQueryBuilder('p')
            ->select('p.paymentDate as date, SUM(p.amount) as total')
            ->join('p.paymentType', 'pt')
            ->where('pt.name = :type')
            ->andWhere('p.paymentDate BETWEEN :start AND :end')
            ->setParameter('type', $paymentType)
            ->setParameter('start', $startOfWeek)
            ->setParameter('end', $endOfWeek)
            ->groupBy('p.paymentDate')
            ->getQuery()
            ->getResult();

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

    public function getTotalPaymentsForCurrentMonth(string $paymentType): float
    {
        $now = new DateTime();
        $startOfMonth = (clone $now)->modify('first day of this month');
        $endOfMonth = (clone $now)->modify('last day of this month');

        return $this->getTotalPaymentsForDateRange($paymentType, $startOfMonth, $endOfMonth);
    }

    public function getTotalPaymentsForCurrentYear(string $paymentType): float
    {
        $now = new DateTime();
        $startOfYear = (clone $now)->modify('first day of January');
        $endOfYear = (clone $now)->modify('last day of December');

        return $this->getTotalPaymentsForDateRange($paymentType, $startOfYear, $endOfYear);
    }

    private function getTotalPaymentsForDateRange(string $paymentType, DateTime $startDate, DateTime $endDate): float
    {
        return (float) $this->createQueryBuilder('p')
            ->select('SUM(p.amount)')
            ->join('p.paymentType', 'pt')
            ->where('pt.name = :type')
            ->andWhere('p.paymentDate BETWEEN :start AND :end')
            ->setParameter('type', $paymentType)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
