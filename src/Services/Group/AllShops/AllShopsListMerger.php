<?php

namespace App\Services\Group\AllShops;

/**
 * Lot G-3 (18/11/2026, contrat validé par le chef) : réunions plus coûteuses de « Toutes les boutiques » — listes (à recevoir, soldes et
 * mouvements de trésorerie), année de l'entreprise par mois civil, résultat du groupe sur des dates. Mêmes règles que `AllShopsMerger` :
 * seules les boutiques `ok` entrent dans les totaux, par devise, jamais deux devises additionnées ; taux recalculés sur les sommes.
 */
final class AllShopsListMerger
{
    /**
     * À recevoir : les lignes de chaque boutique (les `page × limite` premières, triées par date de commande) réunies avec leur boutique et
     * leur devise, retriées, puis la page demandée. Un seul total, toutes devises (des lignes, pas des montants).
     *
     * @param list<array<string, mixed>> $shops
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function toReceive(array $shops, int $page, int $limit): array
    {
        $rows = [];
        $total = 0;
        foreach ($shops as $shop) {
            if ($shop['status'] !== GroupScope::OK) {
                continue;
            }
            $total += (int) $shop['data']['total'];
            foreach ($shop['data']['rows'] as $row) {
                // la devise de la ligne est celle de sa COMMANDE (fournisseur) : jamais remplacée ; celle de la boutique à côté
                $rows[] = $row + ['site' => $shop['site'], 'siteCurrency' => $shop['currency']];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [(string) ($a['commande']['date'] ?? ''), $a['site'], (int) ($a['id'] ?? 0)]
            <=> [(string) ($b['commande']['date'] ?? ''), $b['site'], (int) ($b['id'] ?? 0)]);

        return ['rows' => array_slice($rows, ($page - 1) * $limit, $limit), 'total' => $total];
    }

    /**
     * Soldes de trésorerie : par devise, le dernier solde saisi de chaque boutique additionné (`balance`, avec la date la plus ancienne de
     * ces derniers soldes), et les soldes de toutes les boutiques du plus récent au plus ancien (la limite demandée).
     *
     * @param list<array<string, mixed>> $shops
     * @return list<array<string, mixed>>
     */
    public static function balances(array $shops, int $limit): array
    {
        $totals = [];
        foreach (self::byCurrency($shops) as $currency => $parts) {
            $latest = array_values(array_filter(array_map(static fn (array $p): ?array => $p['data'][0] ?? null, $parts)));
            $rows = [];
            foreach ($parts as $part) {
                foreach ($part['data'] as $row) {
                    $rows[] = ['site' => $part['site']] + $row;
                }
            }
            usort($rows, static fn (array $a, array $b): int => [$b['date'], $a['site'], $b['id']] <=> [$a['date'], $b['site'], $a['id']]);
            $totals[] = ['currency' => $currency, 'shops' => array_map(static fn (array $p): string => $p['site'], $parts),
                'balance' => $latest === [] ? null : (int) array_sum(array_column($latest, 'amount')),
                'balanceShops' => count($latest), 'oldestBalanceDate' => $latest === [] ? null : min(array_column($latest, 'date')),
                'rows' => array_slice($rows, 0, $limit)];
        }

        return $totals;
    }

    /**
     * Mouvements prévus : par devise, ceux de toutes les boutiques avec leur boutique (les salaires restent regroupés : aucun nom).
     *
     * @param list<array<string, mixed>> $shops
     * @return list<array<string, mixed>>
     */
    public static function movements(array $shops): array
    {
        $totals = [];
        foreach (self::byCurrency($shops) as $currency => $parts) {
            $rows = [];
            foreach ($parts as $part) {
                foreach ($part['data'] as $row) {
                    $rows[] = ['site' => $part['site']] + $row;
                }
            }
            $totals[] = ['currency' => $currency, 'shops' => array_map(static fn (array $p): string => $p['site'], $parts), 'rows' => $rows];
        }

        return $totals;
    }

    /**
     * L'année : chaque boutique garde son exercice ; ses mois se réunissent par mois civil (`AAAA-MM`), dans l'ordre du calendrier. Ventes,
     * marge, coût inconnu, autres revenus, frais et résultat additionnés ; taux de marque recalculé (marge ÷ ventes au coût connu) ; cumul
     * refait sur les mois réunis. Projection, point mort et leviers restent à la boutique (ils dépendent de son exercice et de ses budgets).
     *
     * @param list<array<string, mixed>> $shops
     * @return list<array<string, mixed>>
     */
    public static function year(array $shops): array
    {
        $totals = [];
        foreach (self::byCurrency($shops) as $currency => $parts) {
            $byMonth = [];
            foreach ($parts as $part) {
                foreach ($part['data']['months'] ?? [] as $m) {
                    $key = sprintf('%04d-%02d', (int) $m['year'], (int) $m['month']);
                    $byMonth[$key]['blocks'][] = $m;
                    $byMonth[$key]['shops'][] = $part['site'];
                }
            }
            ksort($byMonth);
            $months = [];
            foreach ($byMonth as $key => $b) {
                $elapsed = array_unique(array_map(static fn (array $m) => $m['elapsed'], $b['blocks']));
                $months[] = ['key' => $key, 'month' => (int) substr($key, 5, 2), 'year' => (int) substr($key, 0, 4),
                    'from' => min(array_column($b['blocks'], 'from')), 'to' => max(array_column($b['blocks'], 'to')),
                    'elapsed' => count($elapsed) === 1 ? $elapsed[0] : 'partial', 'shops' => $b['shops']] + self::block($b['blocks']);
            }
            $starts = array_values(array_unique(array_map(static fn (array $p) => $p['data']['fiscalYear']['startMonth'] ?? null, $parts)));
            $sumTotals = self::block(array_map(static fn (array $p): array => $p['data']['totals'], $parts));
            $total = [
                'currency' => $currency, 'shops' => array_map(static fn (array $p): string => $p['site'], $parts),
                'months' => $months, 'cumulative' => self::cumulative($months),
                'totals' => $sumTotals + ['from' => min(array_map(static fn (array $p) => $p['data']['totals']['from'], $parts)), 'to' => max(array_map(static fn (array $p) => $p['data']['totals']['to'], $parts))],
                'profitability' => ['tauxMarque' => $sumTotals['grossMarginRate']],
                'caveats' => count($starts) > 1 ? [['code' => 'FISCAL_YEARS_DIFFER', 'text' => null, 'shops' => array_map(static fn (array $p): string => $p['site'], $parts)]] : [],
            ];
            $totals[] = $total;
        }

        return $totals;
    }

    /**
     * Le résultat du groupe sur des dates : sommes des résultats de chaque boutique, taux recalculés.
     *
     * @param list<array<string, mixed>> $shops
     * @return list<array<string, mixed>>
     */
    public static function result(array $shops): array
    {
        $totals = [];
        foreach (self::byCurrency($shops) as $currency => $parts) {
            $data = array_map(static fn (array $p): array => $p['data'], $parts);
            $sum = self::numbers(array_map(static fn (array $d): array => array_diff_key($d, ['period' => 1, 'currency' => 1, 'expenses' => 1, 'fixedCosts' => 1]), $data));
            $sum['expenses'] = [
                'shipping' => (int) array_sum(array_map(static fn (array $d) => (int) $d['expenses']['shipping'], $data)),
                'notes' => (int) array_sum(array_map(static fn (array $d) => (int) $d['expenses']['notes'], $data)),
                'advertising' => (int) array_sum(array_map(static fn (array $d) => (int) array_sum(array_column($d['expenses']['other'], 'amount')), $data)),
                'total' => (int) array_sum(array_map(static fn (array $d) => (int) $d['expenses']['total'], $data)),
            ];
            $sum['fixedCosts'] = ['total' => (int) array_sum(array_map(static fn (array $d) => (int) ($d['fixedCosts']['total'] ?? 0), $data))];
            $effective = (int) $sum['sales']['revenue'] - (int) $sum['sales']['unknownCostRevenue'];
            $rate = static fn (int $num, int $den): ?float => $den > 0 ? round($num / $den * 100, 2) : null;
            $gross = (int) $sum['result']['gross'];
            $sum['result'] = ['gross' => $gross, 'net' => (int) $sum['result']['net'], 'tauxMarge' => $rate($gross, (int) $sum['cogs']['amount']),
                'tauxMarque' => $rate($gross, $effective), 'netRate' => $rate((int) $sum['result']['net'], $effective),
                'netAfterFixed' => (int) $sum['result']['netAfterFixed'], 'netAfterFixedRate' => $rate((int) $sum['result']['netAfterFixed'], $effective)];
            $totals[] = ['currency' => $currency, 'shops' => array_map(static fn (array $p): string => $p['site'], $parts)] + $sum;
        }

        return $totals;
    }

    /**
     * Somme de blocs d'un mois (ou des totaux) de l'année ; un mois à venir (valeurs nulles) n'ajoute rien ; tous nuls : nul.
     *
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    private static function block(array $blocks): array
    {
        $fields = ['sales', 'grossMargin', 'unknownCostRevenue', 'fixedCosts', 'resultBeforeOtherRevenue', 'result'];
        $out = [];
        foreach ($fields as $f) {
            $values = array_filter(array_map(static fn (array $b) => $b[$f] ?? null, $blocks), static fn ($v) => $v !== null);
            $out[$f] = $values === [] ? null : (int) array_sum($values);
        }
        foreach (['otherRevenue', 'variableCosts'] as $group) {
            $keys = [];
            foreach ($blocks as $b) {
                $keys += is_array($b[$group] ?? null) ? $b[$group] : [];
            }
            foreach (array_keys($keys) as $k) {
                $values = array_filter(array_map(static fn (array $b) => is_array($b[$group] ?? null) ? ($b[$group][$k] ?? null) : null, $blocks), static fn ($v) => $v !== null);
                $out[$group][$k] = $values === [] ? null : (int) array_sum($values);
            }
            $out[$group] ??= null;
        }
        $effective = $out['sales'] === null ? null : $out['sales'] - (int) $out['unknownCostRevenue'];
        $out['grossMarginRate'] = $effective !== null && $effective > 0 && $out['grossMargin'] !== null ? round($out['grossMargin'] / $effective * 100, 1) : null;

        return $out;
    }

    /**
     * Cumul depuis le premier mois réuni, forme plate de la route seule ; s'arrête au premier mois qu'aucune boutique n'a encore commencé.
     *
     * @param list<array<string, mixed>> $months
     * @return list<array<string, mixed>>
     */
    private static function cumulative(array $months): array
    {
        $out = [];
        $run = ['sales' => 0, 'grossMargin' => 0, 'otherRevenue' => 0, 'variableCosts' => 0, 'fixedCosts' => 0, 'result' => 0];
        $stopped = false;
        foreach ($months as $m) {
            if ($stopped || $m['elapsed'] === false || $m['sales'] === null) {
                $stopped = true;
                $out[] = ['month' => $m['key'], 'sales' => null, 'grossMargin' => null, 'otherRevenue' => null, 'variableCosts' => null, 'fixedCosts' => null, 'result' => null];
                continue;
            }
            $run['sales'] += (int) $m['sales'];
            $run['grossMargin'] += (int) $m['grossMargin'];
            $run['otherRevenue'] += (int) ($m['otherRevenue']['total'] ?? 0);
            $run['variableCosts'] += (int) ($m['variableCosts']['total'] ?? 0);
            $run['fixedCosts'] += (int) $m['fixedCosts'];
            $run['result'] += (int) $m['result'];
            $out[] = ['month' => $m['key']] + $run;
        }

        return $out;
    }

    /**
     * Somme champ par champ des nombres (tableaux associatifs imbriqués) ; un texte ou une liste n'est pas repris.
     *
     * @param list<array<string, mixed>> $values
     * @return array<string, mixed>
     */
    private static function numbers(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            foreach ($v as $k => $x) {
                if (is_int($x) || is_float($x)) {
                    $out[$k] = ($out[$k] ?? 0) + $x;
                } elseif (is_array($x) && !array_is_list($x)) {
                    $out[$k] = self::numbers(array_merge(isset($out[$k]) && is_array($out[$k]) ? [$out[$k]] : [], [$x]));
                }
            }
        }

        return $out;
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
}
