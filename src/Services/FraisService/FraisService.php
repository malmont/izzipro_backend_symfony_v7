<?php

namespace App\Services\FraisService;

use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;

/**
 * Totaux des frais d'achat (lot G3, 09/11/2026) : notes de frais (datées par `note_de_frais.date`) et frais de port
 * (datés par la date de leur commande fournisseur, `commande.date`), en cents entiers, en trois requêtes agrégées
 * (année, par mois, par jour de la semaine courante) au lieu d'une requête par mois et par jour. Journée et semaine
 * courantes dans le fuseau du magasin (`StoreClock`) ; les dates des achats sont des dates civiles (sans fuseau).
 * Jusqu'au 09/11/2026, `GET /api/frais/total` était cassée : des chaînes littérales (`'endDate'`…) étaient passées aux
 * requêtes à la place des dates.
 */
class FraisService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly StoreClock $clock)
    {
    }

    /** @return array{year: int, total_frais: array{total: int, note_de_frais: int, frais_de_port: int}} */
    public function getTotalFraisForYear(int $year): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT (SELECT COALESCE(SUM(n.montant), 0) FROM note_de_frais n WHERE EXTRACT(YEAR FROM n.date) = :y) AS notes,' .
            ' (SELECT COALESCE(SUM(f.price), 0) FROM frais_de_port f JOIN commande c ON c.id = f.commande_id WHERE EXTRACT(YEAR FROM c.date) = :y) AS shipping',
            ['y' => $year]
        ) ?: [];

        return ['year' => $year, 'total_frais' => self::block((int) ($row['notes'] ?? 0), (int) ($row['shipping'] ?? 0))];
    }

    /** @return list<array{month: int, total_frais: array{total: int, note_de_frais: int, frais_de_port: int}}> */
    public function getTotalFraisByMonth(int $year): array
    {
        $connection = $this->connection();
        $notes = $connection->fetchAllKeyValue('SELECT EXTRACT(MONTH FROM n.date)::int AS m, COALESCE(SUM(n.montant), 0) FROM note_de_frais n WHERE EXTRACT(YEAR FROM n.date) = :y GROUP BY m', ['y' => $year]);
        $shipping = $connection->fetchAllKeyValue('SELECT EXTRACT(MONTH FROM c.date)::int AS m, COALESCE(SUM(f.price), 0) FROM frais_de_port f JOIN commande c ON c.id = f.commande_id WHERE EXTRACT(YEAR FROM c.date) = :y GROUP BY m', ['y' => $year]);
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[] = ['month' => $m, 'total_frais' => self::block((int) ($notes[$m] ?? 0), (int) ($shipping[$m] ?? 0))];
        }

        return $out;
    }

    /** @return list<array{date: string, total_frais: array{total: int, note_de_frais: int, frais_de_port: int}}> jours inclusifs */
    public function getTotalFraisByDay(\DateTimeInterface $weekStart, \DateTimeInterface $weekEnd): array
    {
        $connection = $this->connection();
        $params = ['from' => $weekStart->format('Y-m-d'), 'to' => $weekEnd->format('Y-m-d')];
        $notes = $connection->fetchAllKeyValue('SELECT to_char(n.date, \'YYYY-MM-DD\') AS d, COALESCE(SUM(n.montant), 0) FROM note_de_frais n WHERE n.date >= :from AND n.date <= :to GROUP BY d', $params);
        $shipping = $connection->fetchAllKeyValue('SELECT to_char(c.date, \'YYYY-MM-DD\') AS d, COALESCE(SUM(f.price), 0) FROM frais_de_port f JOIN commande c ON c.id = f.commande_id WHERE c.date >= :from AND c.date < (CAST(:to AS date) + 1) GROUP BY d', $params);
        $out = [];
        for ($day = \DateTimeImmutable::createFromInterface($weekStart); $day->format('Y-m-d') <= $weekEnd->format('Y-m-d'); $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $out[] = ['date' => $key, 'total_frais' => self::block((int) ($notes[$key] ?? 0), (int) ($shipping[$key] ?? 0))];
        }

        return $out;
    }

    /** Année et semaine courantes du magasin, pour GET /api/frais/total */
    public function currentPeriods(): array
    {
        $now = new \DateTimeImmutable('now', $this->clock->timezone());
        $weekStart = $now->modify('monday this week');

        return ['year' => (int) $now->format('Y'), 'weekStart' => $weekStart, 'weekEnd' => $weekStart->modify('+6 days')];
    }

    private static function block(int $notes, int $shipping): array
    {
        return ['total' => $notes + $shipping, 'note_de_frais' => $notes, 'frais_de_port' => $shipping];
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
