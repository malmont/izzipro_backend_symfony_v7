<?php

namespace App\Services\StatistiqueService;

use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;

/**
 * Statistiques de ventes de l'application de gestion (lot G3, 09/11/2026, `docs/gestion-mise-a-niveau.md` § 3) :
 * chiffre d'affaires, nombre de commandes, panier moyen, transporteurs et taxes, en **une requête agrégée par série**
 * (`SUM … FILTER` pour les totaux de périodes, `GROUP BY` jour / semaine / mois pour les courbes) au lieu d'une requête
 * par point. Périodes bornées dans le **fuseau du magasin** (`StoreClock`, comme la caisse et les paiements) : semaine
 * du lundi 0 h au dimanche 23 h 59 min 59 s, mois et année civils ; `order_date` est stockée en UTC (heure murale) et
 * ramenée dans le fuseau en SQL. Montants en cents entiers ; mois rendus par leur numéro (1..12), jours en date ISO,
 * semaines par leur numéro ISO ; mêmes clés JSON que les anciens services (`StatistiqueRevenuService`,
 * `StatistiqueCommandeService`, `StatistiquePanierService`, `CarrierStatisticsService`, `TaxService`, retirés).
 *
 * Chiffre d'affaires, nombre et panier : ventes et retours (`order_type_id` 1 et 2), la vente d'une carte-cadeau (type 3)
 * est un passif ; panier moyen sur les commandes à montant > 0 ; `orderSource` 1 boutique web, 2 caisse, 3 application mobile.
 */
class SalesStatisticsService
{
    private const REVENUE_TYPES = '(1, 2)';
    /** Commandes incomplètes (1) et annulées (7) : hors des ventes, comme le résultat de période (correctifs B3 à B5, 11/11/2026) */
    private const KEPT_STATUS = ' AND COALESCE(o.status_id, 0) NOT IN (1, 7)';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly StoreClock $clock)
    {
    }

    /** GET /api/statistiques/chiffre-affaires/{source} */
    public function revenueOverview(?int $source): array
    {
        $p = $this->periods();
        $where = 'o.order_type_id IN ' . self::REVENUE_TYPES . ' AND o.canceled_at IS NULL' . $this->sourceWhere($source); // PAYS-1b : vente annulée (montants gardés) = 0, comme avant
        $totals = $this->filtered('SUM(o.total_amount)', $where, $p, $source, ['currentWeek', 'lastWeek', 'currentMonth', 'lastMonth', 'currentYear', 'lastYear']);

        return [
            'currentWeekRevenue' => self::cents($totals['currentWeek']),
            'lastWeekRevenue' => self::cents($totals['lastWeek']),
            'dailyRevenueForCurrentWeek' => $this->daily('SUM(o.total_amount)', $where, $p['currentWeek'], $source, 'revenue', 'cents'),
            'currentMonthRevenue' => self::cents($totals['currentMonth']),
            'lastMonthRevenue' => self::cents($totals['lastMonth']),
            'weeklyRevenueForCurrentMonth' => $this->weekly('SUM(o.total_amount)', $where, $p['currentMonth'], $source, 'revenue', 'cents'),
            'currentYearRevenue' => self::cents($totals['currentYear']),
            'lastYearRevenue' => self::cents($totals['lastYear']),
            'monthlyRevenueForCurrentYear' => $this->monthly('SUM(o.total_amount)', $where, $p['currentYear'], $source, 'revenue', 'cents'),
        ];
    }

    /** Chiffre d'affaires d'un intervalle de jours du magasin (bornes inclusives, Y-m-d) */
    public function customIntervalRevenue(\DateTimeInterface $start, \DateTimeInterface $end, ?int $source): int
    {
        [$from, $to] = $this->clock->range($start->format('Y-m-d'), $end->format('Y-m-d'));
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')] + $this->sourceParam($source);

        return self::cents($this->connection()->fetchOne(
            'SELECT COALESCE(SUM(o.total_amount), 0) FROM "order" o WHERE o.order_date >= :from AND o.order_date < :to AND o.order_type_id IN ' . self::REVENUE_TYPES . ' AND o.canceled_at IS NULL' . $this->sourceWhere($source), // PAYS-1b
            $params
        ));
    }

    /** GET /api/statistiques/nombre-commandes/{source} : clés historiques, accents compris */
    public function orderCountOverview(?int $source): array
    {
        $p = $this->periods();
        $types = [1 => 'AchatClient', 2 => 'RetourClient'];
        $statuses = [3 => 'Complétée', 6 => 'Livrée', 7 => 'Annulation'];
        $base = 'o.order_type_id IN ' . self::REVENUE_TYPES . $this->sourceWhere($source);

        // Une requête : comptes par type et statut pour la semaine courante et la précédente
        $sql = 'SELECT o.order_type_id AS t, o.status_id AS s,' .
            ' COUNT(*) FILTER (WHERE o.order_date >= :currentWeek_from AND o.order_date < :currentWeek_to) AS current_week,' .
            ' COUNT(*) FILTER (WHERE o.order_date >= :lastWeek_from AND o.order_date < :lastWeek_to) AS last_week' .
            ' FROM "order" o WHERE ' . $base . ' AND o.order_date >= :lastWeek_from AND o.order_date < :currentWeek_to GROUP BY o.order_type_id, o.status_id';
        $byTypeStatus = [];
        foreach ($this->connection()->fetchAllAssociative($sql, $this->boundParams($p, ['currentWeek', 'lastWeek']) + $this->sourceParam($source)) as $row) {
            $byTypeStatus[(int) $row['t']][(int) $row['s']] = $row;
        }
        // Une requête : comptes par jour, type et statut sur la semaine courante
        $dailyRows = $this->connection()->fetchAllAssociative(
            'SELECT o.order_type_id AS t, o.status_id AS s, ' . $this->dayExpr() . ' AS day, COUNT(*) AS n FROM "order" o WHERE ' . $base .
            ' AND o.order_date >= :from AND o.order_date < :to GROUP BY o.order_type_id, o.status_id, day',
            ['from' => $p['currentWeek'][0]->format('Y-m-d H:i:s'), 'to' => $p['currentWeek'][1]->format('Y-m-d H:i:s'), 'tz' => $this->tz()] + $this->sourceParam($source)
        );
        $daily = [];
        foreach ($dailyRows as $row) {
            $daily[(int) $row['t']][(int) $row['s']][$row['day']] = (int) $row['n'];
        }
        $days = self::days($p['currentWeek'][0], 7);

        $statistics = [];
        foreach ($types as $typeId => $typeName) {
            foreach ($statuses as $statusId => $statusName) {
                if ($typeId === 2 && $statusId === 7) {
                    continue;
                }
                $statistics["currentWeekCount{$typeName}{$statusName}"] = (int) ($byTypeStatus[$typeId][$statusId]['current_week'] ?? 0);
                $statistics["lastWeekCount{$typeName}{$statusName}"] = (int) ($byTypeStatus[$typeId][$statusId]['last_week'] ?? 0);
                $statistics["dailyCount{$typeName}_{$statusName}ForCurrentWeek"] = array_map(fn (string $day) => ['date' => $day, 'orderCount' => $daily[$typeId][$statusId][$day] ?? 0], $days);
            }
        }
        $totals = $this->filtered('COUNT(*)', $base, $p, $source, ['currentMonth', 'lastMonth', 'currentYear', 'lastYear']);
        $statistics['currentMonthCount'] = (int) $totals['currentMonth'];
        $statistics['lastMonthCount'] = (int) $totals['lastMonth'];
        $statistics['weeklyCountForCurrentMonth'] = $this->weekly('COUNT(*)', $base, $p['currentMonth'], $source, 'orderCount', 'int');
        $statistics['currentYearCount'] = (int) $totals['currentYear'];
        $statistics['lastYearCount'] = (int) $totals['lastYear'];
        $statistics['monthlyCountForCurrentYear'] = $this->monthly('COUNT(*)', $base, $p['currentYear'], $source, 'orderCount', 'int');

        // B4 (additif) : ventes seulement (type 1), hors incomplètes et annulées ; les clés ci-dessus restent (la caisse lit /pos)
        $sales = 'o.order_type_id = 1' . self::KEPT_STATUS . $this->sourceWhere($source);
        $salesTotals = $this->filtered('COUNT(*)', $sales, $p, $source, ['currentMonth', 'lastMonth', 'currentYear', 'lastYear']);
        $statistics['currentMonthSalesCount'] = (int) $salesTotals['currentMonth'];
        $statistics['lastMonthSalesCount'] = (int) $salesTotals['lastMonth'];
        $statistics['weeklySalesCountForCurrentMonth'] = $this->weekly('COUNT(*)', $sales, $p['currentMonth'], $source, 'orderCount', 'int');
        $statistics['currentYearSalesCount'] = (int) $salesTotals['currentYear'];
        $statistics['lastYearSalesCount'] = (int) $salesTotals['lastYear'];
        $statistics['monthlySalesCountForCurrentYear'] = $this->monthly('COUNT(*)', $sales, $p['currentYear'], $source, 'orderCount', 'int');

        return $statistics;
    }

    /**
     * GET /api/statistiques/panier-moyen/{source} (clé historique `lastAverageCount` = mois précédent) ; B3 (11/11/2026) : commandes incomplètes
     * et annulées exclues, `lastYearAverage` (année civile précédente) ajouté
     */
    public function averageOrderValueOverview(?int $source): array
    {
        $p = $this->periods();
        $where = 'o.order_type_id IN ' . self::REVENUE_TYPES . ' AND o.total_amount > 0' . self::KEPT_STATUS . $this->sourceWhere($source);
        $totals = $this->filtered('AVG(o.total_amount)', $where, $p, $source, ['currentWeek', 'lastWeek', 'currentMonth', 'lastMonth', 'currentYear', 'lastYear']);

        return [
            'currentWeekAverage' => self::cents($totals['currentWeek']),
            'lastWeekAverage' => self::cents($totals['lastWeek']),
            'dailyAverageForCurrentWeek' => $this->daily('AVG(o.total_amount)', $where, $p['currentWeek'], $source, 'averageOrderValue', 'cents'),
            'currentMonthAverage' => self::cents($totals['currentMonth']),
            'lastAverageCount' => self::cents($totals['lastMonth']),
            'weeklyAverageForCurrentMonth' => $this->weekly('AVG(o.total_amount)', $where, $p['currentMonth'], $source, 'averageOrderValue', 'cents'),
            'currentYearAverage' => self::cents($totals['currentYear']),
            'monthlyAverageForCurrentYear' => $this->monthly('AVG(o.total_amount)', $where, $p['currentYear'], $source, 'averageOrderValue', 'cents'),
            'lastYearAverage' => self::cents($totals['lastYear']),
        ];
    }

    /**
     * GET /api/carrier/statistics : montant des transporteurs de livraison par mois de l'année courante (cents). `carrier_count` / `shippingAmount`
     * (historiques) = prix catalogue des transporteurs, sans filtre ; B5 (11/11/2026, additif) : `shippingBilled` = frais de port réellement
     * facturés (`order.shipping_cost` des ventes et retours hors incomplètes et annulées, un remboursement de port négatif se déduirait),
     * `orders` = ventes avec transporteur (mêmes statuts)
     */
    public function carrierMonthly(): array
    {
        $p = $this->periods();
        $rows = $this->groupBy('SUM(c.price)', 'o.carrier_id IS NOT NULL', $p['currentYear'], null, $this->monthExpr(), 'JOIN carrier c ON c.id = o.carrier_id');
        $billed = $this->groupBy('SUM(o.shipping_cost)', 'o.order_type_id IN ' . self::REVENUE_TYPES . self::KEPT_STATUS, $p['currentYear'], null, $this->monthExpr());
        $orders = $this->groupBy('COUNT(*)', 'o.order_type_id = 1 AND o.carrier_id IS NOT NULL' . self::KEPT_STATUS, $p['currentYear'], null, $this->monthExpr());
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $amount = self::cents($rows[$m] ?? 0);
            $months[] = ['month' => $m, 'carrier_count' => $amount, 'shippingAmount' => $amount, 'shippingBilled' => self::cents($billed[$m] ?? 0), 'orders' => (int) ($orders[$m] ?? 0)];
        }

        return ['monthly_carrier_for_current_year' => $months];
    }

    /** GET /api/taxes/monthly : taxes des commandes clients par mois de l'année courante (cents) */
    public function taxesMonthly(): array
    {
        $p = $this->periods();
        $rows = $this->groupBy('SUM(t.amount)', 'o.canceled_at IS NULL', $p['currentYear'], null, $this->monthExpr(), 'JOIN order_tax t ON t.order_tax_id = o.id'); // PAYS-1b : taxes d'une vente annulée = 0, comme avant
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = ['month' => $m, 'total_tax' => self::cents($rows[$m] ?? 0)];
        }

        return ['monthly_taxes_for_current_year' => $months];
    }

    /** Fuseau du magasin et bornes UTC des périodes courantes et précédentes */
    public function periods(): array
    {
        $tz = $this->clock->timezone();
        $now = new \DateTimeImmutable('now', $tz);
        $weekStart = $now->modify('monday this week')->setTime(0, 0);
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);
        $yearStart = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0);

        return [
            'now' => $now,
            'currentWeek' => [$weekStart, $weekStart->modify('+7 days')],
            'lastWeek' => [$weekStart->modify('-7 days'), $weekStart],
            'currentMonth' => [$monthStart, $monthStart->modify('+1 month')],
            'lastMonth' => [$monthStart->modify('-1 month'), $monthStart],
            'currentYear' => [$yearStart, $yearStart->modify('+1 year')],
            'lastYear' => [$yearStart->modify('-1 year'), $yearStart],
        ];
    }

    // ----- requêtes -----

    /** Totaux de plusieurs périodes en une requête (`COALESCE(agrégat FILTER (WHERE période), 0)`) ; `$aggregate` = `SUM(…)`, `AVG(…)` ou `COUNT(*)` */
    private function filtered(string $aggregate, string $where, array $p, ?int $source, array $names): array
    {
        $selects = [];
        foreach ($names as $name) {
            $selects[] = sprintf('COALESCE(%s FILTER (WHERE o.order_date >= :%s_from AND o.order_date < :%s_to), 0) AS %s', $aggregate, $name, $name, strtolower(preg_replace('/([A-Z])/', '_$1', $name)));
        }
        $earliest = min(array_map(fn ($n) => $p[$n][0], $names));
        $latest = max(array_map(fn ($n) => $p[$n][1], $names));
        $row = $this->connection()->fetchAssociative(
            'SELECT ' . implode(', ', $selects) . ' FROM "order" o WHERE ' . $where . ' AND o.order_date >= :earliest AND o.order_date < :latest',
            $this->boundParams($p, $names) + ['earliest' => self::utc($earliest), 'latest' => self::utc($latest)] + $this->sourceParam($source)
        ) ?: [];
        $out = [];
        foreach ($names as $name) {
            $out[$name] = $row[strtolower(preg_replace('/([A-Z])/', '_$1', $name))] ?? 0;
        }

        return $out;
    }

    /** Points par jour d'une période : [{date, <key>}], jours vides à 0 */
    private function daily(string $aggregate, string $where, array $period, ?int $source, string $key, string $type): array
    {
        $rows = $this->groupBy($aggregate, $where, $period, $source, $this->dayExpr());
        $out = [];
        foreach (self::days($period[0], (int) $period[0]->diff($period[1])->days) as $day) {
            $out[] = ['date' => $day, $key => self::typed($rows[$day] ?? 0, $type)];
        }

        return $out;
    }

    /** Points par semaine ISO d'un mois : [{week, <key>}], semaines présentes seulement (comme avant) */
    private function weekly(string $aggregate, string $where, array $period, ?int $source, string $key, string $type): array
    {
        $end = min($period[1], $this->periods()['now']->modify('+1 day')->setTime(0, 0));
        $rows = $this->groupBy($aggregate, $where, [$period[0], $end], $source, $this->weekExpr());
        $out = [];
        for ($day = $period[0]; $day < $end; $day = $day->modify('+7 days')->modify('monday this week')) {
            $week = (int) $day->format('W');
            $out[] = ['week' => $week, $key => self::typed($rows[$week] ?? 0, $type)];
        }

        return $out;
    }

    /** Points par mois de l'année courante jusqu'au mois courant : [{month: 1..n, <key>}] */
    private function monthly(string $aggregate, string $where, array $period, ?int $source, string $key, string $type): array
    {
        $rows = $this->groupBy($aggregate, $where, $period, $source, $this->monthExpr());
        $out = [];
        $current = (int) $this->periods()['now']->format('n');
        for ($m = 1; $m <= $current; $m++) {
            $out[] = ['month' => $m, $key => self::typed($rows[$m] ?? 0, $type)];
        }

        return $out;
    }

    /** @return array<string|int, mixed> valeur agrégée par clé de groupe */
    private function groupBy(string $aggregate, string $where, array $period, ?int $source, string $groupExpr, string $join = ''): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT ' . $groupExpr . ' AS k, COALESCE(' . $aggregate . ', 0) AS v FROM "order" o ' . $join . ' WHERE ' . $where . $this->sourceWhere($source) .
            ' AND o.order_date >= :from AND o.order_date < :to GROUP BY k',
            ['from' => self::utc($period[0]), 'to' => self::utc($period[1]), 'tz' => $this->tz()] + $this->sourceParam($source)
        );
        $out = [];
        foreach ($rows as $row) {
            $out[is_numeric($row['k']) ? (int) $row['k'] : (string) $row['k']] = $row['v'];
        }

        return $out;
    }

    private function dayExpr(): string
    {
        return "to_char((o.order_date AT TIME ZONE 'UTC') AT TIME ZONE :tz, 'YYYY-MM-DD')";
    }

    private function weekExpr(): string
    {
        return "EXTRACT(WEEK FROM (o.order_date AT TIME ZONE 'UTC') AT TIME ZONE :tz)::int";
    }

    private function monthExpr(): string
    {
        return "EXTRACT(MONTH FROM (o.order_date AT TIME ZONE 'UTC') AT TIME ZONE :tz)::int";
    }

    private function boundParams(array $p, array $names): array
    {
        $params = [];
        foreach ($names as $name) {
            $params[$name . '_from'] = self::utc($p[$name][0]);
            $params[$name . '_to'] = self::utc($p[$name][1]);
        }

        return $params;
    }

    private function sourceWhere(?int $source): string
    {
        return $source === null ? '' : ' AND o.order_source_id = :source';
    }

    private function sourceParam(?int $source): array
    {
        return $source === null ? [] : ['source' => $source];
    }

    private function tz(): string
    {
        return $this->clock->timezone()->getName();
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @return string[] jours Y-m-d à partir d'une date du magasin */
    private static function days(\DateTimeImmutable $start, int $count): array
    {
        $days = [];
        for ($i = 0; $i < $count; $i++) {
            $days[] = $start->modify("+$i days")->format('Y-m-d');
        }

        return $days;
    }

    private static function typed(mixed $value, string $type): int
    {
        return $type === 'int' ? (int) $value : self::cents($value);
    }

    private static function cents(mixed $value): int
    {
        return (int) round((float) $value);
    }

    private function connection(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
