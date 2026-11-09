<?php

namespace App\Services\GestionService;

use App\Entity\Collections;
use App\Entity\Entreprise;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;

/**
 * Calendrier des périodes de gestion (lot P1, 10/11/2026, `docs/periodes-gestion.md`). Une période est une ligne de
 * `collections` avec `kind = period`, repérée par `period_key` (unique). Fréquence du site : `entreprise.purchase_period_frequency`
 * (`quarterly` par défaut : printemps 01/03–31/05, été 01/06–31/08, automne 01/09–30/11, hiver 01/12–fin février ; `semester` ;
 * `monthly`). Jours civils dans le fuseau du site (`StoreClock`). Création idempotente **à la lecture**, sans trou : de la plus
 * ancienne activité du site (24 mois au plus) à la période suivant celle d'aujourd'hui (`INSERT … ON CONFLICT DO NOTHING`).
 * Un changement de fréquence prend effet après la dernière période existante : jamais de chevauchement.
 * État d'une période : fermée quand `closed_at` est posée (clôture manuelle) ou, sans réouverture, quand aujourd'hui dépasse
 * la fin de 15 jours ; une période rouverte reste ouverte jusqu'à une clôture manuelle.
 */
class PeriodCalendar
{
    public const FREQUENCIES = ['quarterly', 'semester', 'monthly'];
    public const DEFAULT_FREQUENCY = 'quarterly';
    public const GRACE_DAYS = 15;
    public const MAX_BACKFILL_MONTHS = 24;

    private const MONTHS_FR = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly StoreClock $clock)
    {
    }

    /** Fréquence du site (réglage `gestion.periods`) */
    public function frequency(): string
    {
        $e = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']);
        $f = $e?->getPurchasePeriodFrequency();

        return in_array($f, self::FREQUENCIES, true) ? $f : self::DEFAULT_FREQUENCY;
    }

    /** Aujourd'hui (jour civil du site) */
    public function today(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', $this->clock->timezone()))->setTime(0, 0);
    }

    /**
     * Période du calendrier contenant une date, pour une fréquence donnée (sans lecture en base).
     *
     * @return array{key: string, name: string, start: \DateTimeImmutable, end: \DateTimeImmutable}
     */
    public static function definition(\DateTimeInterface $date, string $frequency): array
    {
        $d = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
        $y = (int) $d->format('Y');
        $m = (int) $d->format('n');
        switch ($frequency) {
            case 'monthly':
                $start = $d->setDate($y, $m, 1);

                return ['key' => sprintf('%d-M%02d', $y, $m), 'name' => self::MONTHS_FR[$m] . ' ' . $y, 'start' => $start, 'end' => $start->modify('last day of this month')];
            case 'semester':
                $s = $m <= 6 ? 1 : 2;
                $start = $d->setDate($y, $s === 1 ? 1 : 7, 1);

                return ['key' => sprintf('%d-S%d', $y, $s), 'name' => sprintf('Semestre %d %d', $s, $y), 'start' => $start, 'end' => $start->modify('+6 months')->modify('-1 day')];
            default: // trimestres saisonniers : l'hiver chevauche deux années civiles et porte l'année de janvier / février
                if ($m >= 3 && $m <= 5) {
                    return ['key' => "$y-Q2", 'name' => "Printemps $y", 'start' => $d->setDate($y, 3, 1), 'end' => $d->setDate($y, 5, 31)];
                }
                if ($m >= 6 && $m <= 8) {
                    return ['key' => "$y-Q3", 'name' => "Été $y", 'start' => $d->setDate($y, 6, 1), 'end' => $d->setDate($y, 8, 31)];
                }
                if ($m >= 9 && $m <= 11) {
                    return ['key' => "$y-Q4", 'name' => "Automne $y", 'start' => $d->setDate($y, 9, 1), 'end' => $d->setDate($y, 11, 30)];
                }
                $winterYear = $m === 12 ? $y + 1 : $y; // décembre appartient à l'hiver de l'année suivante

                return ['key' => "$winterYear-Q1", 'name' => sprintf('Hiver %d-%d', $winterYear - 1, $winterYear), 'start' => $d->setDate($winterYear - 1, 12, 1), 'end' => $d->setDate($winterYear, 2, 1)->modify('last day of this month')];
        }
    }

    /**
     * Crée les périodes manquantes (idempotent) et rend la période contenant la date, ou null si la date est antérieure à toute
     * période possible (plus de 24 mois avant aujourd'hui et sans activité).
     */
    public function periodFor(\DateTimeInterface $date): ?Collections
    {
        $this->ensurePeriods($date);

        return $this->findByDate($date);
    }

    /** Période d'aujourd'hui (créée si manquante) */
    public function current(): Collections
    {
        $period = $this->periodFor($this->today());
        if ($period === null) {
            throw new \RuntimeException('Période courante introuvable après création');
        }

        return $period;
    }

    /**
     * Toutes les périodes manquantes depuis la plus ancienne activité du site (ou `$from` si antérieure, 24 mois au plus) jusqu'à la
     * période suivant celle d'aujourd'hui. Les périodes existantes gardent leurs dates ; les nouvelles suivent la fréquence actuelle
     * à partir du lendemain de la fin de la dernière période existante. Si `$from` est antérieur à la première période existante
     * (semis, reprise d'historique), les périodes manquantes avant elle sont créées aussi (24 mois au plus).
     *
     * @return int nombre de périodes créées
     */
    public function ensurePeriods(?\DateTimeInterface $from = null): int
    {
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $frequency = $this->frequency();
        $today = $this->today();
        $floor = $today->modify('-' . self::MAX_BACKFILL_MONTHS . ' months')->modify('first day of this month');

        [$earliestStart, $latestEnd] = array_values($conn->fetchAssociative("SELECT MIN(start_date_collection) AS s, MAX(end_date_collection) AS e FROM collections WHERE kind = 'period'") ?: ['s' => null, 'e' => null]);
        if ($latestEnd) {
            $cursor = (new \DateTimeImmutable(substr((string) $latestEnd, 0, 10)))->modify('+1 day');
        } else {
            $earliest = $conn->fetchOne("SELECT LEAST(COALESCE((SELECT MIN(date) FROM commande), :today), COALESCE((SELECT MIN(date) FROM note_de_frais), :today), COALESCE((SELECT MIN(order_date) FROM \"order\"), :today))", ['today' => $today->format('Y-m-d')]);
            $cursor = $earliest ? new \DateTimeImmutable(substr((string) $earliest, 0, 10)) : $today;
            if ($from !== null && $from < $cursor) {
                $cursor = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0);
            }
            if ($cursor < $floor) {
                $cursor = $floor;
            }
        }
        $stop = self::definition($today, $frequency)['end']->modify('+1 day'); // la période suivant celle d'aujourd'hui est créée aussi
        $ownerId = $conn->fetchOne('SELECT id FROM "user" WHERE roles::text LIKE :r ORDER BY id ASC LIMIT 1', ['r' => '%ROLE_ADMIN%']);
        $created = 0;
        $fill = function (\DateTimeImmutable $cursor, \DateTimeImmutable $stop) use ($conn, $frequency, $ownerId, &$created): void {
            $guard = 0;
            while ($cursor <= $stop && $guard++ < 400) {
                $def = self::definition($cursor, $frequency);
                $created += (int) $conn->executeStatement(
                    "INSERT INTO collections (id, user_collections_id, budget_collection, start_date_collection, end_date_collection, del, nom_collection, is_closed, kind, period_key)" .
                    " SELECT nextval('collections_id_seq'), :owner, 0, :start, :end, false, :name, false, 'period', :key" .
                    " WHERE NOT EXISTS (SELECT 1 FROM collections WHERE kind = 'period' AND period_key = :key) ON CONFLICT DO NOTHING",
                    ['owner' => $ownerId ?: null, 'start' => $def['start']->format('Y-m-d 00:00:00'), 'end' => $def['end']->format('Y-m-d 00:00:00'), 'name' => $def['name'], 'key' => $def['key']]
                );
                $cursor = $def['end']->modify('+1 day');
            }
        };
        $fill($cursor, $stop);
        // reprise vers le passé (semis, reprise d'historique) : des périodes existent déjà et `$from` est antérieur à la première
        if ($from !== null && $earliestStart) {
            $first = new \DateTimeImmutable(substr((string) $earliestStart, 0, 10));
            $start = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0);
            if ($start < $floor) {
                $start = $floor;
            }
            if ($start < $first) {
                $fill($start, $first->modify('-1 day'));
            }
        }

        return $created;
    }

    /** Période (en base) contenant la date, sans création */
    public function findByDate(\DateTimeInterface $date): ?Collections
    {
        $day = \DateTimeImmutable::createFromInterface($date)->format('Y-m-d');

        return $this->emProvider->getEntityManager()->getRepository(Collections::class)->createQueryBuilder('c')
            ->where("c.kind = 'period'")->andWhere('c.startDateCollection <= :d')->andWhere('c.endDateCollection >= :d')
            ->setParameter('d', $day . ' 00:00:00')->orderBy('c.id', 'ASC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** `open` | `closed` d'après la date du site (fin + 15 jours), la clôture manuelle et la réouverture ; collection manuelle : `isClosed` */
    public function status(Collections $collection): string
    {
        return $this->isClosed($collection) ? 'closed' : 'open';
    }

    public function isClosed(Collections $collection): bool
    {
        if (!$collection->isPeriod()) {
            return (bool) $collection->getIsClosed();
        }
        if ($collection->getClosedAt() !== null) {
            return true;
        }
        if ($collection->getReopenedAt() !== null) {
            return false;
        }

        return $this->today() > $this->deadline($collection);
    }

    /** Dernier jour d'écriture d'une période : fin + 15 jours */
    public function deadline(Collections $collection): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($collection->getEndDateCollection())->setTime(0, 0)->modify('+' . self::GRACE_DAYS . ' days');
    }

    /** Date effective de clôture (manuelle, sinon fin + 15 jours quand elle est passée), null si ouverte */
    public function closedAt(Collections $collection): ?\DateTimeImmutable
    {
        if (!$this->isClosed($collection)) {
            return null;
        }
        if ($collection->getClosedAt() !== null) {
            return \DateTimeImmutable::createFromInterface($collection->getClosedAt());
        }

        return $collection->isPeriod() ? $this->deadline($collection) : null;
    }
}
