<?php

namespace App\Gestion\Services;

use App\Entity\Collections;
use App\Gestion\Entity\GestionBudgetLine;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Simulateur de période de la gestion (lot B1b-2, 11/11/2026, `docs/simulateur-gestion.md`) : à partir des données en cours (réalisé de la
 * période, rythme de vente des 30 derniers jours par variante plafonné au stock, sinon les mêmes jours de l'an dernier, prévu par poste,
 * trésorerie saisie), compare un scénario de base et un scénario simulé (prix, promotion, publicité, prévu par poste). **Aucune écriture** :
 * « valider » = l'application envoie `plannedToSave` au PUT du budget existant. Le réalisé ne bouge jamais : les leviers de prix ne touchent
 * que les ventes restantes. Montants en cents, devise du site, hors taxes comme le résultat de période.
 */
class GestionSimulationService
{
    public const PACE_WINDOW_DAYS = 30;
    public const MAX_PRODUCT_IDS = 500;
    public const EXPENSE_CATEGORIES = ['trip', 'shipping', 'advertising', 'other']; // charges du résultat ; purchases n'en est pas une

    /** Facteur hors taxes d'une ligne de commande client (même règle que le résultat de période) */
    private const NET_FACTOR = 'CASE WHEN o.prices_include_tax AND COALESCE(o.sub_total, 0) <> 0 THEN (o.sub_total - COALESCE(o.total_tax, 0)) / o.sub_total ELSE 1 END';
    /** Coût unitaire courant d'un article : moyenne pondérée de ses lignes d'achat au coût connu (× taux), sinon prix d'achat de la fiche */
    private const COST = 'COALESCE((SELECT COALESCE(SUM(l.quantity_ordered * l.unit_cost_cents * COALESCE(lc.exchange_rate, 1)) / NULLIF(SUM(l.quantity_ordered), 0), AVG(l.unit_cost_cents * COALESCE(lc.exchange_rate, 1)))'
        . ' FROM commande_line l JOIN commande lc ON lc.id = l.commande_id WHERE l.product_id = p.id AND l.unit_cost_cents IS NOT NULL AND CAST(lc.date AS date) <= :today), p.purchase_price)';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodCalendar $calendar,
        private readonly StoreClock $clock,
        private readonly PeriodResultCalculator $results,
        private readonly GestionBudgetService $budgets,
        private readonly GestionCashService $cash,
        private readonly TranslatorInterface $translator,
        private readonly ?TenantCurrencyProvider $currency = null,
        private readonly ?GestionFixedCostService $fixedCosts = null, // lot FF2
    ) {
    }

    /** @return array<string, mixed> */
    public function simulate(Collections $period, array $body, string $locale, ?\DateTimeImmutable $today = null): array
    {
        // lot FF2 : frais fixes de la période au prorata (FF1), en variante « après frais fixes » ; le résultat principal ne change pas
        $fixed = $this->fixedCosts?->between($period->getStartDateCollection()->format('Y-m-d'), $period->getEndDateCollection()->format('Y-m-d'), $locale)['total'] ?? 0;
        if (!$period->isPeriod()) {
            throw new GestionConflictException('NOT_A_PERIOD', 'Cette collection est manuelle : le simulateur concerne les périodes.', 404, ['collectionId' => $period->getId()]);
        }
        if ($this->calendar->isClosed($period)) {
            throw new GestionConflictException('PERIOD_CLOSED', 'Cette période est fermée : il n\'y a plus rien à décider.', 409, ['periodId' => $period->getId()]);
        }
        $levers = $this->levers($body);
        $today ??= $this->calendar->today();
        $todayKey = $today->format('Y-m-d');
        $start = $period->getStartDateCollection()->format('Y-m-d');
        $end = $period->getEndDateCollection()->format('Y-m-d');
        $tomorrow = $today->modify('+1 day')->format('Y-m-d');
        $remainingFrom = max($tomorrow, $start);
        $daysTotal = $this->days($start, $end);
        $daysRemaining = $remainingFrom > $end ? 0 : $this->days($remainingFrom, $end);
        $daysElapsed = $todayKey < $start ? 0 : min($daysTotal, $this->days($start, $todayKey));

        // Réalisé (résultat de période, sans coupure : la période est ouverte) et budget par poste (prévu enregistré, réel)
        $result = $this->results->compute($period, null);
        $budget = $this->budgets->get($period, $locale);
        $saved = array_column($budget['lines'], 'planned', 'category');
        $actual = array_column($budget['lines'], 'actual', 'category');
        $plannedSim = array_replace($saved, $levers['planned']);

        // Ventes restantes par variante : rythme des 30 derniers jours, sinon les mêmes jours l'an dernier
        $method = 'none';
        $variants = [];
        if ($daysRemaining > 0) {
            $pace = $this->soldByVariant($today->modify('-' . self::PACE_WINDOW_DAYS . ' days')->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d'), $todayKey);
            if ($pace !== []) {
                $method = 'pace';
                foreach ($pace as $vid => $v) {
                    $variants[$vid] = $v + ['projected' => min($v['qty'] * $daysRemaining / self::PACE_WINDOW_DAYS, (float) $v['stock'])];
                }
            } else {
                $ly = $this->soldByVariant((new \DateTimeImmutable($remainingFrom))->modify('-1 year')->format('Y-m-d'), (new \DateTimeImmutable($end))->modify('-1 year')->format('Y-m-d'), $todayKey);
                if ($ly !== []) {
                    $method = 'lastYear';
                    foreach ($ly as $vid => $v) {
                        $variants[$vid] = $v + ['projected' => min((float) $v['qty'], (float) $v['stock'])];
                    }
                }
            }
        }
        // Promotions datées en cours ou à venir (lot P5) : la part des ventes restantes qui tombe pendant la promotion est valorisée au prix
        // promotionnel (prix moyen × prix promotionnel ÷ prix normal), à quantités égales ; jamais pour une variante à prix propre (règle de prix)
        $promoted = [];
        if ($variants !== [] && $daysRemaining > 0) {
            $promos = [];
            foreach ($this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
                'SELECT id, price, special_price, special_price_from, special_price_to FROM product WHERE id IN (?) AND special_price > 0 AND price > 0',
                [array_values(array_unique(array_column($variants, 'productId')))], [\Doctrine\DBAL\ArrayParameterType::INTEGER]
            ) as $r) {
                $utc = new \DateTimeZone('UTC');
                $from = $r['special_price_from'] === null ? $remainingFrom : max($remainingFrom, $this->clock->day(new \DateTimeImmutable((string) $r['special_price_from'], $utc)));
                $to = $r['special_price_to'] === null ? $end : min($end, $this->clock->day(new \DateTimeImmutable((string) $r['special_price_to'], $utc)));
                if ($to >= $from) {
                    $promos[(int) $r['id']] = ['share' => $this->days($from, $to) / $daysRemaining, 'ratio' => (float) $r['special_price'] / (float) $r['price']];
                }
            }
            foreach ($variants as $vid => &$v) {
                if (isset($promos[$v['productId']]) && !$v['ownPrice']) {
                    $v['unitPrice'] *= 1 - $promos[$v['productId']]['share'] * (1 - $promos[$v['productId']]['ratio']);
                    $promoted[$v['productId']] = true;
                }
            }
            unset($v);
        }
        $stock = $this->stockByVariant($todayKey);
        $promoProducts = $levers['promotion'] === null ? [] : ($levers['promotion']['scope'] === 'all' ? null : array_flip($levers['promotion']['productIds']));

        $realized = [
            'revenue' => (int) $result['sales']['revenue'], 'unknown' => (int) $result['sales']['unknownCostRevenue'], 'cogs' => (int) $result['cogs']['amount'],
            'units' => (int) $result['sales']['items'] - (int) $result['sales']['returns']['items'],
        ];
        $cashBase = $this->cash->cashUntil(max($end, $todayKey), $today);
        $scenario = function (bool $simulated) use ($variants, $stock, $levers, $promoProducts, $realized, $saved, $plannedSim, $actual, $cashBase, $locale, $fixed): array {
            $factor = fn (int $productId): float => !$simulated ? 1.0
                : (1 + $levers['pricePercent'] / 100) * ($levers['promotion'] !== null && ($promoProducts === null || isset($promoProducts[$productId])) ? 1 - $levers['promotion']['percent'] / 100 : 1);
            $units = 0.0;
            $revenue = 0.0;
            $cogs = 0.0;
            $unknown = 0.0;
            $sold = [];
            foreach ($variants as $vid => $v) {
                $r = $v['projected'] * $v['unitPrice'] * $factor($v['productId']);
                $units += $v['projected'];
                $revenue += $r;
                $sold[$vid] = $v['projected'];
                if ($v['cost'] === null) {
                    $unknown += $r;
                } else {
                    $cogs += $v['projected'] * $v['cost'];
                }
            }
            $stockEnd = ['units' => 0.0, 'costValue' => 0.0, 'saleValue' => 0.0];
            foreach ($stock as $vid => $s) {
                $left = max(0.0, $s['stock'] - ($sold[$vid] ?? 0.0));
                $stockEnd['units'] += $left;
                $stockEnd['costValue'] += $left * (float) ($s['cost'] ?? 0);
                $stockEnd['saleValue'] += $left * $s['price'] * $factor($s['productId']);
            }
            $planned = $simulated ? $plannedSim : $saved;
            $byCategory = [];
            $remainingSpend = 0;
            foreach (self::EXPENSE_CATEGORIES as $c) {
                $amount = max((int) ($planned[$c] ?? 0), (int) ($actual[$c] ?? 0));
                $remaining = max(0, (int) ($planned[$c] ?? 0) - (int) ($actual[$c] ?? 0));
                if ($simulated && $c === 'advertising') {
                    $amount = max(0, $amount + $levers['advertisingDelta']);
                    $remaining = max(0, $remaining + $levers['advertisingDelta']);
                }
                $remainingSpend += $remaining;
                $byCategory[] = ['category' => $c, 'label' => $this->translator->trans("budget.categories.$c", [], 'gestion', $locale), 'amount' => $amount];
            }
            $expenses = array_sum(array_column($byCategory, 'amount'));
            $projected = (int) round($revenue);
            $totalRevenue = $realized['revenue'] + $projected;
            $totalUnknown = $realized['unknown'] + (int) round($unknown);
            $totalCogs = $realized['cogs'] + (int) round($cogs);
            $effective = $totalRevenue - $totalUnknown;
            $gross = $effective - $totalCogs;
            $rate = $effective > 0 ? $gross / $effective : null;
            $breakEven = $rate !== null && $gross > 0 ? (int) ceil($expenses * $effective / $gross) : null; // ventes au point mort = frais ÷ taux de marque
            $breakEvenFixed = $rate !== null && $gross > 0 ? (int) ceil(($expenses + $fixed) * $effective / $gross) : null;
            $remainingPurchases = max(0, (int) ($planned['purchases'] ?? 0) - (int) ($actual['purchases'] ?? 0));

            return [
                'sales' => ['realized' => $realized['revenue'], 'projected' => $projected, 'total' => $totalRevenue],
                'units' => ['realized' => $realized['units'], 'projected' => (int) round($units)],
                'cogs' => $totalCogs, 'unknownCostRevenue' => $totalUnknown, 'grossMargin' => $gross,
                'tauxMarque' => $rate === null ? null : round($rate * 100, 2),
                'expenses' => ['byCategory' => $byCategory, 'total' => $expenses],
                'result' => $gross - $expenses,
                'breakEven' => $breakEven === null ? null : ['revenue' => $breakEven, 'reached' => $effective >= $breakEven],
                'fixedCosts' => $fixed, 'resultAfterFixed' => $gross - $expenses - $fixed,
                'breakEvenAfterFixed' => $breakEvenFixed === null ? null : ['revenue' => $breakEvenFixed, 'reached' => $effective >= $breakEvenFixed],
                'projectedSales' => ['units' => (int) round($units), 'revenue' => $projected],
                'stockEnd' => ['units' => (int) round($stockEnd['units']), 'costValue' => (int) round($stockEnd['costValue']), 'saleValue' => (int) round($stockEnd['saleValue'])],
                'cashAfterPurchases' => $cashBase === null ? null : $cashBase['amount'] + $projected - $remainingPurchases - $remainingSpend,
            ];
        };
        $base = $scenario(false);
        $simulated = $scenario(true);

        $caveats = [];
        if ($levers['pricePercent'] != 0 || $levers['promotion'] !== null) {
            $caveats[] = 'SAME_QUANTITIES';
        }
        if ($fixed > 0) {
            $caveats[] = 'FIXED_COSTS_INCLUDED';
        }
        if ($base['unknownCostRevenue'] > 0 || $simulated['unknownCostRevenue'] > 0) {
            $caveats[] = 'UNKNOWN_COST';
        }
        if ($method === 'lastYear') {
            $caveats[] = 'LAST_YEAR_USED';
        }
        if ($method === 'none' && $daysRemaining > 0) {
            $caveats[] = 'NO_SALES_PACE';
        }
        if ($promoted !== []) {
            $caveats[] = 'PROMOTIONS_INCLUDED';
        }
        if ($cashBase === null) {
            $caveats[] = 'NO_CASH_BALANCE';
        } else {
            if ($cashBase['balance']['ageDays'] > GestionCashService::STALE_DAYS) {
                $caveats[] = 'STALE_BALANCE';
            }
            $caveats[] = 'TAXES_EXCLUDED';
        }
        if (max(0, (int) ($plannedSim['purchases'] ?? 0) - (int) ($actual['purchases'] ?? 0)) > 0 || max(0, (int) ($saved['purchases'] ?? 0) - (int) ($actual['purchases'] ?? 0)) > 0) {
            $caveats[] = 'PLANNED_PURCHASES_NOT_IN_STOCK';
        }
        $ly = $this->results->salesMetrics(
            \DateTimeImmutable::createFromInterface($period->getStartDateCollection())->modify('-1 year'),
            \DateTimeImmutable::createFromInterface($period->getEndDateCollection())->modify('-1 year')
        );

        return [
            'period' => ['id' => $period->getId(), 'name' => $period->getNomCollection(), 'start' => $start, 'end' => $end, 'daysTotal' => $daysTotal, 'daysElapsed' => $daysElapsed, 'daysRemaining' => $daysRemaining],
            'currency' => $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT,
            'levers' => ['planned' => (object) $levers['planned']] + $levers,
            'method' => $method,
            'lastYear' => $ly['orders'] === 0 && $ly['revenue'] === 0 ? null : ['revenue' => $ly['revenue'], 'grossMargin' => $ly['grossMargin'], 'units' => $ly['items']],
            'base' => $base,
            'simulated' => $simulated,
            'delta' => self::delta($base, $simulated),
            'plannedToSave' => ['lines' => array_map(fn (string $c) => ['category' => $c, 'planned' => (int) ($plannedSim[$c] ?? 0)], GestionBudgetLine::CATEGORIES)],
            'caveats' => array_map(fn (string $code) => ['code' => $code, 'text' => $this->translator->trans("simulation.caveats.$code", ['%days%' => $cashBase['balance']['ageDays'] ?? 0], 'gestion', $locale)]
                + ($code === 'PROMOTIONS_INCLUDED' ? ['productIds' => array_keys($promoted)] : []), $caveats),
        ];
    }

    /**
     * Écart simulé − base, même forme ; seuls les nombres restent (booléens et textes omis, sauf `category` qui identifie une ligne) ; null si
     * l'un des deux est null.
     */
    public static function delta(array $base, array $simulated): array
    {
        $out = [];
        foreach ($simulated as $key => $value) {
            $b = $base[$key] ?? null;
            if ($key === 'category') {
                $out[$key] = $value;
            } elseif (is_array($value) || is_array($b)) {
                $out[$key] = is_array($value) && is_array($b) ? self::delta($b, $value) : null;
            } elseif (is_bool($value) || is_string($value)) {
                continue;
            } elseif ($value === null || $b === null) {
                $out[$key] = null;
            } else {
                $out[$key] = is_float($value) || is_float($b) ? round($value - $b, 2) : $value - $b;
            }
        }

        return $out;
    }

    // ----- interne -----

    /**
     * Unités nettes vendues par variante sur [from, to] (jours du magasin) avec le prix moyen obtenu hors taxes et net des remises, le stock et le
     * coût unitaire courants ; variantes vendues (nettes des retours) seulement ; locations, abonnements et lignes libres exclus.
     *
     * @return array<int, array{productId: int, qty: int, unitPrice: float, stock: int, cost: ?float}>
     */
    public function soldByVariant(string $from, string $to, string $today): array
    {
        [$start, $endExclusive] = $this->clock->range($from, $to);
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'WITH s AS (SELECT oi.product_variant_id AS vid, SUM(CASE WHEN o.order_type_id = 2 THEN -oi.quantity ELSE oi.quantity END) AS qty,'
            . ' SUM((CASE WHEN o.order_type_id = 2 THEN -ABS(oi.total_price) ELSE oi.total_price END) * (' . self::NET_FACTOR . ')) AS net'
            . ' FROM order_items oi JOIN "order" o ON o.id = oi.order_associated_id LEFT JOIN booking b ON b.order_item_id = oi.id'
            . ' WHERE o.order_date >= :from AND o.order_date < :to AND o.order_type_id IN (1, 2) AND o.status_id NOT IN (1, 7) AND b.id IS NULL AND o.subscription_id IS NULL AND oi.product_variant_id IS NOT NULL'
            . ' GROUP BY oi.product_variant_id)'
            . ' SELECT s.vid, s.qty, s.net, pv.product_id, (pv.price IS NOT NULL) AS own_price, GREATEST(COALESCE(pv.stock_quantity, 0), 0) AS stock, ' . self::COST . ' AS cost'
            . ' FROM s JOIN product_variant pv ON pv.id = s.vid JOIN product p ON p.id = pv.product_id WHERE s.qty > 0',
            ['from' => $start->format('Y-m-d H:i:s'), 'to' => $endExclusive->format('Y-m-d H:i:s'), 'today' => $today]
        );
        $out = [];
        foreach ($rows as $r) {
            $qty = (int) $r['qty'];
            $out[(int) $r['vid']] = ['productId' => (int) $r['product_id'], 'qty' => $qty, 'unitPrice' => max(0.0, (float) $r['net'] / $qty), 'stock' => (int) $r['stock'], 'cost' => $r['cost'] === null ? null : (float) $r['cost'],
                'ownPrice' => in_array($r['own_price'], [true, 't', 1, '1'], true)];
        }

        return $out;
    }

    /**
     * Stock en vente par variante (articles standard hors location, stock > 0) : prix affiché (variante, sinon article) et coût unitaire courant.
     *
     * @return array<int, array{productId: int, stock: int, price: int, cost: ?float}>
     */
    public function stockByVariant(string $today): array
    {
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT pv.id, pv.product_id, pv.stock_quantity AS stock, COALESCE(pv.price, p.price, 0) AS price, ' . self::COST . ' AS cost'
            . " FROM product_variant pv JOIN product p ON p.id = pv.product_id WHERE p.product_type = 'standard' AND NOT COALESCE(p.rental_enabled, false) AND COALESCE(pv.stock_quantity, 0) > 0",
            ['today' => $today]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = ['productId' => (int) $r['product_id'], 'stock' => (int) $r['stock'], 'price' => (int) $r['price'], 'cost' => $r['cost'] === null ? null : (float) $r['cost']];
        }

        return $out;
    }

    /** @return array{pricePercent: float|int, promotion: ?array{percent: float|int, scope: string, productIds: list<int>}, advertisingDelta: int, planned: array<string, int>} */
    private function levers(array $body): array
    {
        $errors = [];
        $number = function (mixed $v, string $field, float $min, float $max) use (&$errors): float|int {
            if ($v === null) {
                return 0;
            }
            if (!is_int($v) && !(is_float($v) && is_finite($v))) {
                $errors[$field] = 'MUST_BE_NUMBER';

                return 0;
            }
            if ($v < $min || $v > $max) {
                $errors[$field] = 'OUT_OF_RANGE';
            }

            return $v;
        };
        $pricePercent = $number($body['pricePercent'] ?? null, 'pricePercent', -90, 300);
        $promotion = null;
        $raw = $body['promotion'] ?? null;
        if ($raw !== null) {
            if (!is_array($raw) || array_is_list($raw)) {
                $errors['promotion'] = 'MUST_BE_OBJECT';
            } else {
                $percent = $number($raw['percent'] ?? null, 'promotion.percent', 0, 90);
                $scope = is_string($raw['scope'] ?? null) ? strtolower(trim($raw['scope'])) : 'all';
                $ids = [];
                if (!in_array($scope, ['all', 'products'], true)) {
                    $errors['promotion.scope'] = 'INVALID_SCOPE';
                } elseif ($scope === 'products') {
                    $list = $raw['productIds'] ?? null;
                    if (!is_array($list) || !array_is_list($list) || $list === []) {
                        $errors['promotion.productIds'] = 'REQUIRED';
                    } elseif (count($list) > self::MAX_PRODUCT_IDS) {
                        $errors['promotion.productIds'] = 'TOO_MANY';
                    } else {
                        foreach ($list as $i => $id) {
                            if (!is_int($id) || $id <= 0) {
                                $errors["promotion.productIds[$i]"] = 'INVALID_ID';
                            } else {
                                $ids[$i] = $id;
                            }
                        }
                        $known = $ids === [] ? [] : array_map('intval', $this->emProvider->getEntityManager()->getConnection()->fetchFirstColumn(
                            'SELECT id FROM product WHERE id IN (?)', [array_values(array_unique($ids))], [\Doctrine\DBAL\ArrayParameterType::INTEGER]
                        ));
                        foreach ($ids as $i => $id) {
                            if (!in_array($id, $known, true)) {
                                $errors["promotion.productIds[$i]"] = 'PRODUCT_NOT_FOUND';
                            }
                        }
                    }
                }
                $promotion = ['percent' => $percent, 'scope' => $scope, 'productIds' => array_values(array_unique($ids))];
            }
        }
        $delta = $body['advertisingDelta'] ?? 0;
        if (!is_int($delta)) {
            $errors['advertisingDelta'] = 'MUST_BE_INTEGER_CENTS';
            $delta = 0;
        }
        $planned = [];
        $rawPlanned = $body['planned'] ?? null;
        if ($rawPlanned !== null) {
            if (!is_array($rawPlanned) || ($rawPlanned !== [] && array_is_list($rawPlanned))) {
                $errors['planned'] = 'MUST_BE_OBJECT';
            } else {
                foreach ($rawPlanned as $category => $value) {
                    if (!in_array($category, GestionBudgetLine::CATEGORIES, true)) {
                        $errors["planned.$category"] = 'INVALID_CATEGORY';
                    } elseif (!is_int($value) || $value < 0) {
                        $errors["planned.$category"] = 'MUST_BE_INTEGER_CENTS';
                    } else {
                        $planned[$category] = $value;
                    }
                }
            }
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Simulation invalide');
        }

        return ['pricePercent' => $pricePercent, 'promotion' => $promotion, 'advertisingDelta' => $delta, 'planned' => $planned];
    }

    /** Nombre de jours de [from, to] inclus */
    private function days(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%a') + 1;
    }
}
