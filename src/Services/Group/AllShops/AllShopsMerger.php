<?php

namespace App\Services\Group\AllShops;

use App\Gestion\Services\GestionStatisticsService;

/**
 * Lot G-2 : réunit les parts des boutiques (`AllShopsReader`) en totaux PAR DEVISE ; jamais deux devises additionnées. Seules les parts au
 * statut `ok` entrent dans les totaux ; chaque boutique garde sa réponse telle quelle dans `shops[].data`.
 *
 * - Statistiques : les SOMMES brutes de chaque boutique (ventes brutes, retours, coût, coût inconnu, commandes) sont repassées à
 *   `GestionStatisticsService::metrics()` : les taux (marge, panier, retours) sont recalculés, jamais moyennés. Les points du graphique
 *   se réunissent par clé (jour, semaine ou mois). Une comparaison (plage précédente, an dernier, valeur du stock passée) manquante dans
 *   une boutique est comptée comme absente : le total ne porte que les boutiques qui l'ont, et la remarque `PARTIAL_COMPARISON` le dit.
 * - Journée et pertes : sommes champ par champ, listes réunies par leur clé (canal, moyen, statut, règle) ; ce qui ne s'additionne pas
 *   (date et heure du magasin, état de la trésorerie, postes de caisse) suit une règle écrite plus bas.
 */
final class AllShopsMerger
{
    /**
     * La réponse de « Toutes les boutiques » : le groupe, les totaux par devise, et chaque boutique avec son statut et sa réponse telle quelle
     * (les sommes internes de la fusion n'en sortent pas).
     *
     * @param array<string, mixed> $group
     * @param array{shops: list<array<string, mixed>>, truncated: bool} $read
     * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $merge
     * @return array<string, mixed>
     */
    public static function response(array $group, array $read, callable $merge): array
    {
        return [
            'scope' => 'group',
            'group' => ['id' => (int) $group['id'], 'name' => (string) $group['name']],
            'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
            'truncated' => $read['truncated'],
            'totals' => $merge($read['shops']),
            'shops' => array_map(static function (array $shop): array {
                unset($shop['sums']);

                return $shop;
            }, $read['shops']),
        ];
    }

    /**
     * @param list<array<string, mixed>> $shops
     * @return list<array<string, mixed>>
     */
    public static function statistics(array $shops): array
    {
        $totals = [];
        foreach (self::byCurrency($shops) as $currency => $parts) {
            $sums = array_map(static fn (array $p): array => $p['sums'], $parts);
            $data = array_map(static fn (array $p): array => $p['data'], $parts);
            $partial = []; // boutiques à qui manque une comparaison que d'autres ont
            $sites = array_map(static fn (array $p): string => $p['site'], $parts);
            $all = static function (string $k) use ($sums, $sites, &$partial): ?array {
                $rows = array_filter(array_map(static fn (array $s) => $s[$k], $sums), static fn ($r) => $r !== null);
                if ($rows !== [] && count($rows) < count($sums)) {
                    array_push($partial, ...array_values(array_diff_key($sites, $rows)));
                }

                return $rows === [] ? null : array_values($rows);
            };
            $m = GestionStatisticsService::metrics(array_map(static fn (array $s): array => $s['current'], $sums));
            $prevRows = $all('previous');
            $mp = $prevRows === null ? null : GestionStatisticsService::metrics($prevRows);
            $hasPrevious = $data[0]['previous'] !== null;
            $lyRows = $all('lastYear');
            $ml = $lyRows === null ? null : GestionStatisticsService::metrics($lyRows);
            $archive = $lyRows !== null && array_filter($lyRows, static fn (array $r): bool => !empty($r['archive'])) !== [];
            $lyOf = static fn (string $k) => $ml === null || ($archive && !in_array($k, ['revenue', 'orders', 'averageBasket'], true)) ? null : $ml[$k];

            // graphique : par clé de point, dans l'ordre des clés
            $byKey = [];
            foreach ($sums as $s) {
                foreach ($s['points'] as $point) {
                    $byKey[$point['key']]['from'] = min($byKey[$point['key']]['from'] ?? $point['from'], $point['from']);
                    $byKey[$point['key']]['to'] = max($byKey[$point['key']]['to'] ?? $point['to'], $point['to']);
                    $byKey[$point['key']]['rows'][] = $point;
                    $byKey[$point['key']]['lastYear'][] = $point['lastYear'];
                }
            }
            ksort($byKey);
            $series = array_map(static fn (array $b): array => GestionStatisticsService::metrics($b['rows']), $byKey);
            $points = [];
            foreach ($byKey as $key => $b) {
                $pl = GestionStatisticsService::metrics($b['lastYear']);
                // comme la route seule : une archive d'avant la migration ne connaît pas la marge
                $points[] = ['key' => (string) $key, 'from' => $b['from'], 'to' => $b['to'], 'revenue' => $series[$key]['revenue'], 'grossMargin' => $series[$key]['grossMargin'], 'orders' => $series[$key]['orders'],
                    'lastYear' => ['revenue' => $pl['revenue'], 'grossMargin' => $archive ? null : $pl['grossMargin'], 'orders' => $pl['orders']]];
            }
            $spark = static fn (string $k): array => array_values(array_map(static fn (array $pm) => $pm[$k], $series));
            $indicator = static fn (string $k, string $unit) => GestionStatisticsService::indicator($m[$k], $mp[$k] ?? null, $lyOf($k), $unit, $spark($k), $hasPrevious);
            $stock = static function (string $k) use ($sums, $sites, &$partial): ?int {
                $values = array_map(static fn (array $s) => $s['stock'][$k], $sums);
                $known = array_filter($values, static fn ($v) => $v !== null);
                if ($known !== [] && count($known) < count($values)) {
                    array_push($partial, ...array_values(array_diff_key($sites, $known)));
                }

                return $known === [] ? null : (int) array_sum($known);
            };
            $other = static fn (string $k) => GestionStatisticsService::indicator($m[$k], $mp[$k] ?? null, $lyOf($k), 'money', null, $hasPrevious);
            $indicators = [
                'revenue' => $indicator('revenue', 'money'),
                'grossMargin' => $indicator('grossMargin', 'money'),
                'grossMarginRate' => $indicator('grossMarginRate', 'percent'),
                'orders' => $indicator('orders', 'count'),
                'averageBasket' => $indicator('averageBasket', 'money'),
                'returnRate' => $indicator('returnRate', 'percent') + ['returns' => ['amount' => $m['returns'], 'orders' => $m['returnOrders']]],
                'stockValue' => GestionStatisticsService::indicator($stock('now'), $stock('previous'), $stock('lastYear'), 'money', [], $hasPrevious) + ['costValue' => $stock('cost')],
            ];
            $total = [
                'currency' => $currency,
                'shops' => array_map(static fn (array $p): string => $p['site'], $parts),
                'range' => ['from' => min(array_map(static fn (array $d) => $d['range']['from'], $data)), 'to' => max(array_map(static fn (array $d) => $d['range']['to'], $data)),
                    'type' => $data[0]['range']['type'], 'granularity' => $data[0]['range']['granularity']],
                'indicators' => $indicators,
                'otherRevenue' => ['rentals' => $other('rentals'), 'subscriptions' => $other('subscriptions')],
                'chart' => ['granularity' => $data[0]['range']['granularity'], 'points' => $points],
                'caveats' => array_map(static fn (array $c): array => $c['code'] === 'UNKNOWN_COST' ? ['amount' => $m['unknown']] + $c : $c, self::caveats($parts)),
            ];
            if ($partial !== []) {
                $total['caveats'][] = ['code' => 'PARTIAL_COMPARISON', 'text' => null, 'shops' => array_values(array_unique($partial))];
            }
            $totals[] = $total;
        }

        return $totals;
    }

    /**
     * @param list<array<string, mixed>> $shops
     * @return list<array<string, mixed>>
     */
    public static function day(array $shops): array
    {
        $totals = [];
        foreach (self::byCurrency($shops) as $currency => $parts) {
            $data = array_map(static fn (array $p): array => $p['data'], $parts);
            $sum = self::sum($data);
            // ce qui ne s'additionne pas : le jour et l'heure de chaque boutique ; la comparaison au même jour de semaine est recalculée
            unset($sum['date'], $sum['timezone'], $sum['generatedAt'], $sum['currency']);
            // la comparaison ne porte que sur les boutiques qui ont un historique (moyenne non nulle) : une boutique neuve ne la gonfle pas
            $compared = array_values(array_filter($data, static fn (array $d): bool => (int) ($d['sales']['vsSameWeekdayAverage']['average'] ?? 0) > 0));
            $average = (int) array_sum(array_map(static fn (array $d): int => (int) $d['sales']['vsSameWeekdayAverage']['average'], $compared));
            $today = (int) array_sum(array_map(static fn (array $d): int => (int) $d['sales']['total'], $compared));
            $sum['sales']['vsSameWeekdayAverage'] = ['percent' => $average > 0 ? round(($today - $average) / $average * 100, 1) : null,
                'average' => $average, 'weeks' => $data[0]['sales']['vsSameWeekdayAverage']['weeks'] ?? null, 'asOf' => null,
                'shops' => array_values(array_map(static fn (array $p): string => $p['site'], array_filter($parts, static fn (array $p): bool => (int) ($p['data']['sales']['vsSameWeekdayAverage']['average'] ?? 0) > 0)))];
            $sum['toShip']['oldestAt'] = self::earliest(array_map(static fn (array $d) => $d['toShip']['oldestAt'] ?? null, $data));
            foreach (['outstandingStoreCredit', 'outstandingGiftCards'] as $balance) { // l'instant de lecture de chaque boutique : sans objet pour le total
                if (isset($sum['receipts'][$balance]) && is_array($sum['receipts'][$balance])) {
                    $sum['receipts'][$balance]['asOf'] = null;
                }
            }
            $cash = array_values(array_filter(array_map(static fn (array $d) => $d['cash'] ?? null, $data)));
            $sum['cash'] = $cash === [] ? null : ['balance' => (int) array_sum(array_map(static fn (array $c) => (int) $c['balance'], $cash)), 'shops' => count($cash),
                'state' => self::worst(array_map(static fn (array $c) => (string) $c['state'], $cash))];
            $sum['screens'] = $data[0]['screens'] ?? null;
            $totals[] = ['currency' => $currency, 'shops' => array_map(static fn (array $p): string => $p['site'], $parts)] + $sum;
        }

        return $totals;
    }

    /**
     * @param list<array<string, mixed>> $shops
     * @return list<array<string, mixed>>
     */
    public static function losses(array $shops): array
    {
        $totals = [];
        foreach (self::byCurrency($shops) as $currency => $parts) {
            $data = array_map(static fn (array $p): array => $p['data'], $parts);
            $sum = self::sum($data);
            // les postes de caisse sont propres à chaque boutique (identifiants de sa base) : jamais réunis ; l'analyse non plus
            unset($sum['counts']['byRegister'], $sum['lastAnalysis'], $sum['currency']);
            // le « aujourd'hui » de chaque boutique (fuseaux différents) : la plage qui les couvre toutes
            $periods = array_values(array_filter(array_map(static fn (array $d) => $d['period'] ?? null, $data)));
            $sum['period'] = $periods === [] ? null : ['from' => min(array_column($periods, 'from')), 'to' => max(array_column($periods, 'to'))];
            $sum['caveats'] = self::caveats($parts);
            $totals[] = ['currency' => $currency, 'shops' => array_map(static fn (array $p): string => $p['site'], $parts)] + $sum;
        }

        return $totals;
    }

    /**
     * Les champs d'une part des pertes que reçoit le directeur : des comptes, par poste et par jour, jamais par personne (aucun nom
     * de salarié ; la notice aux salariés reste dans la boutique).
     *
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    public static function lossPart(array $summary): array
    {
        $counts = $summary['counts'] ?? [];

        return [
            'period' => $summary['period'] ?? null,
            'counts' => ['open' => $counts['open'] ?? 0, 'watch' => $counts['watch'] ?? 0, 'new' => $counts['new'] ?? 0, 'active' => $counts['active'] ?? 0,
                'byStatus' => $counts['byStatus'] ?? [], 'byRule' => $counts['byRule'] ?? [],
                'byRegister' => array_map(static fn (array $r): array => ['id' => $r['id'], 'name' => $r['name'], 'open' => $r['open'], 'total' => $r['total']], $counts['byRegister'] ?? [])],
            'amountAtStake' => $summary['amountAtStake'] ?? 0,
            'currency' => $summary['currency'] ?? null,
            'cashDesk' => $summary['cashDesk'] ?? null,
            'lastAnalysis' => ['at' => $summary['lastAnalysis']['at'] ?? null, 'pending' => $summary['lastAnalysis']['pending'] ?? null, 'blocked' => $summary['lastAnalysis']['blocked'] ?? null],
            'caveats' => $summary['caveats'] ?? [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $shops
     * @return array<string, list<array<string, mixed>>>
     */
    private static function byCurrency(array $shops): array
    {
        $by = [];
        foreach ($shops as $shop) {
            if ($shop['status'] === GroupScope::OK) {
                $by[(string) $shop['currency']][] = $shop;
            }
        }
        ksort($by);

        return $by;
    }

    /**
     * Somme champ par champ : nombres additionnés ; listes d'objets réunies par leur clé (canal, moyen, statut, règle, code) ; texte
     * gardé s'il est le même partout, sinon null ; une valeur nulle dans une boutique est ignorée (module absent, rien de saisi).
     *
     * @param list<mixed> $values
     */
    private static function sum(array $values): mixed
    {
        $values = array_values(array_filter($values, static fn ($v) => $v !== null));
        if ($values === []) {
            return null;
        }
        $first = $values[0];
        if (is_int($first) || is_float($first)) {
            $total = array_sum(array_map(static fn ($v) => is_int($v) || is_float($v) ? $v : 0, $values));

            return is_float($total) ? round($total, 2) : $total;
        }
        if (!is_array($first)) {
            return count(array_unique(array_map(static fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $values))) === 1 ? $first : null;
        }
        if (array_is_list($first)) {
            $all = array_merge(...array_map(static fn ($v) => is_array($v) ? $v : [], $values));
            $key = null;
            foreach (['channel', 'method', 'status', 'code'] as $candidate) {
                if ($all !== [] && is_array($all[0]) && array_key_exists($candidate, $all[0])) {
                    $key = $candidate;
                    break;
                }
            }
            if ($key === null) {
                return $all === [] ? [] : null; // liste sans clé : ne se réunit pas (vide partout : vide)
            }
            $groups = [];
            foreach ($all as $item) {
                $groups[(string) $item[$key]][] = $item;
            }

            return array_values(array_map(static fn (array $g): mixed => self::sum($g), $groups));
        }
        $out = [];
        foreach (array_keys(array_merge(...array_map(static fn ($v) => is_array($v) ? $v : [], $values))) as $field) {
            $out[$field] = self::sum(array_map(static fn ($v) => is_array($v) ? ($v[$field] ?? null) : null, $values));
        }

        return $out;
    }

    /**
     * Remarques réunies : un code une fois, avec les boutiques qui la portent ; le texte est gardé s'il est le même dans toutes (un texte
     * qui cite le montant ou le compte d'une boutique ne vaut pas pour le groupe : null)
     *
     * @param list<array<string, mixed>> $parts
     * @return list<array<string, mixed>>
     */
    private static function caveats(array $parts): array
    {
        $by = [];
        foreach ($parts as $part) {
            foreach ($part['data']['caveats'] ?? [] as $caveat) {
                $code = (string) ($caveat['code'] ?? '');
                $by[$code] ??= ['code' => $code, 'text' => $caveat['text'] ?? null, 'shops' => []];
                if ($by[$code]['text'] !== ($caveat['text'] ?? null)) {
                    $by[$code]['text'] = null;
                }
                $by[$code]['shops'][] = $part['site'];
            }
        }

        return array_values($by);
    }

    /** @param list<?string> $dates */
    private static function earliest(array $dates): ?string
    {
        $known = array_values(array_filter($dates, static fn ($d) => is_string($d) && $d !== ''));
        if ($known === []) {
            return null;
        }
        usort($known, static fn (string $a, string $b): int => strtotime($a) <=> strtotime($b));

        return $known[0];
    }

    /** @param list<string> $states */
    private static function worst(array $states): string
    {
        foreach (['critical', 'warning'] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return 'ok';
    }
}
