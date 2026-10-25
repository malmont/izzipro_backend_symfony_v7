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
        // Semaine du magasin : lundi 0 h → dimanche 23 h 59 min 59 s dans le fuseau du magasin (F14, StoreClock), bornes ramenées en UTC
        // (correctifs du 01/11/2026 : les bornes gardaient l'heure courante et la journée était celle du serveur)
        $now = $this->storeNow();
        $startOfWeek = (clone $now)->modify('monday this week')->setTime(0, 0, 0);
        $endOfWeek = (clone $startOfWeek)->modify('+6 days')->setTime(23, 59, 59);
        [$startOfWeek, $endOfWeek] = [self::utc($startOfWeek), self::utc($endOfWeek)];

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
        // Journées du magasin (fuseau de StoreClock, F14) : une vente du soir tombe dans son jour local, pas dans celui du serveur (01/11/2026)
        $now = $this->storeNow();
        $tz = $now->getTimezone();
        $startLocal = (clone $now)->modify('monday this week')->setTime(0, 0, 0);
        $endLocal = (clone $startLocal)->modify('+6 days')->setTime(23, 59, 59);
        [$startOfWeek, $endOfWeek] = [self::utc($startLocal), self::utc($endLocal)];

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
        $period = new \DatePeriod($startLocal, new \DateInterval('P1D'), (clone $endLocal)->modify('+1 second'));
        foreach ($period as $date) {
            $dailyPayments[$date->format('Y-m-d')] = 0;
        }
        foreach ($qb->getQuery()->getResult() as $row) {
            $date = $row['date'];
            // Heure de paiement en base = UTC (heure du serveur) ; journée du magasin après conversion dans son fuseau
            $day = $date instanceof \DateTimeInterface ? \DateTime::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->setTimezone($tz)->format('Y-m-d') : substr((string) $date, 0, 10);
            if (array_key_exists($day, $dailyPayments)) {
                $dailyPayments[$day] = (float) $dailyPayments[$day] + (float) $row['amount'];
            }
        }

        return $dailyPayments;
    }

    /**
     * Mois courant du magasin : du 1er à 0 h au dernier jour à 23 h 59 min 59 s, dans le fuseau du magasin (F14, StoreClock), bornes
     * ramenées en UTC (heure des paiements en base). Correctif du 01/11/2026 : les bornes gardaient l'heure courante du serveur.
     */
    public function getTotalPaymentsForCurrentMonth(string $paymentType, ?int $orderSource = null): float
    {
        $now = $this->storeNow();
        $startOfMonth = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
        $endOfMonth = (clone $now)->modify('last day of this month')->setTime(23, 59, 59);

        return $this->getTotalPaymentsForDateRange($paymentType, self::utc($startOfMonth), self::utc($endOfMonth), $orderSource);
    }

    /** Année courante du magasin : du 1er janvier à 0 h au 31 décembre à 23 h 59 min 59 s (fuseau du magasin, bornes en UTC) */
    public function getTotalPaymentsForCurrentYear(string $paymentType, ?int $orderSource = null): float
    {
        $now = $this->storeNow();
        $startOfYear = (clone $now)->modify('first day of January')->setTime(0, 0, 0);
        $endOfYear = (clone $now)->modify('last day of December')->setTime(23, 59, 59);

        return $this->getTotalPaymentsForDateRange($paymentType, self::utc($startOfYear), self::utc($endOfYear), $orderSource);
    }

    /** Maintenant dans le fuseau du magasin (réglage, sinon province ou pays du site ; F14) */
    private function storeNow(): DateTime
    {
        $entreprise = $this->getEntityManager()->getRepository(\App\Entity\Entreprise::class)->findOneBy([]);

        return new DateTime('now', new \DateTimeZone(\App\Services\CaisseService\StoreClock::resolve($entreprise)['timezone']));
    }

    /** Doctrine compare les dates telles qu'elles sont formatées : les bornes sont ramenées en UTC, comme les heures de paiement */
    private static function utc(DateTime $date): DateTime
    {
        return (clone $date)->setTimezone(new \DateTimeZone('UTC'));
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