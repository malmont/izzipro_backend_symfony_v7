<?php

namespace App\Services\GestionService;

use App\Entity\Collections;
use App\Entity\GestionBudgetLine;
use App\Entity\MarketingCampaign;
use App\Services\TenantEntityManagerProvider;

/**
 * Règles de détection du guide de la gestion (lot G1a, 11/11/2026, `docs/guide-ia-gestion.md` § 3) : le backend repère et chiffre chaque
 * conseil ; l'IA ne fait que rédiger et classer. Chaque règle rend des candidats :
 * `{ signature, moment, kind, subject, action, effect { amount, label }, confidence, sources [{ key, value, unit }], figures, pinned }`.
 * `effect.amount` est signé du point de vue du gérant : positif = argent gagné ou libéré, négatif = coût ou risque.
 */
class AdviceRules
{
    // Moments
    public const END_PHASE_DAYS = 14;          // fin de période : 14 derniers jours (ou dernier tiers d'une période de moins de 42 jours)
    public const CLOSING_DAYS = 60;            // bilan jusqu'à 60 jours après la fin
    // R1 FAST_SELLER
    public const FAST_MIN_SOLD = 5;
    public const FAST_MAX_COVERAGE_DAYS = 21;
    public const FAST_PRICE_PERCENT = 5;
    public const FAST_MEDIUM_FROM = 10;
    public const MAX_PER_RULE = 3;
    // R2 SLOW_STOCK
    public const SLOW_DAYS = 45;
    public const SLOW_MIN_COST_VALUE = 10000;
    public const PROMOTION_PERCENT = 20;
    // R3 AD_RELAUNCH
    public const AD_ENDED_WITHIN_DAYS = 60;
    public const AD_MIN_GAIN = 2.0;
    // R4 BUDGET_PACE
    public const BUDGET_AHEAD = 0.2;
    // R6 TRIP_COEFFICIENT
    public const COEF_REFERENCE_DAYS = 365;
    public const COEF_MIN_LINES = 20;
    public const COEF_GAP = 0.9;
    // R8 PURCHASE_CAPACITY
    public const CAPACITY_HORIZON_DAYS = 90;
    // R9 END_CLEARANCE
    public const CLEARANCE_SHARE = 0.3;
    public const CLEARANCE_MIN = 50000;
    public const CLEARANCE_MAX_PRODUCTS = 5;
    public const CLEARANCE_SLOW_MAX_SOLD = 2;
    // R10 PACE_VS_LAST_YEAR
    public const PACE_BEHIND = 0.85;
    public const PACE_AHEAD = 1.15;
    // R11 CLOSING
    public const STOCKOUT_MIN_SOLD = 5;
    public const LESSON_BUDGET_OVER = 0.1;
    public const LESSON_MAX_FIGURES = 5;
    // Plafond et poids
    public const MAX_ADVICES = 8;
    public const WEIGHTS = ['high' => 1.0, 'medium' => 0.6, 'low' => 0.3];
    public const PINNED = ['CASH_TIGHT', 'PURCHASE_CAPACITY'];

    private ?string $asOf = null; // jour de calcul (coût courant des articles à cette date)

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodCalendar $calendar,
        private readonly PeriodResultCalculator $results,
        private readonly GestionBudgetService $budgets,
        private readonly GestionTripService $trips,
        private readonly GestionCashService $cash,
        private readonly GestionSimulationService $simulation,
        private readonly CampaignProfitabilityCalculator $campaigns,
        private readonly ?\App\Services\CaisseService\StoreClock $clock = null,
    ) {
    }

    /**
     * Moments de la période à cette date.
     *
     * @return array{moments: list<string>, daysTotal: int, daysRemaining: int, elapsedShare: float, remainingFrom: string}
     */
    public function moments(Collections $period, \DateTimeImmutable $today): array
    {
        $start = $period->getStartDateCollection()->format('Y-m-d');
        $end = $period->getEndDateCollection()->format('Y-m-d');
        $t = $today->format('Y-m-d');
        $total = self::days($start, $end);
        $remainingFrom = max($today->modify('+1 day')->format('Y-m-d'), $start);
        $remaining = $remainingFrom > $end ? 0 : self::days($remainingFrom, $end);
        $elapsed = $t < $start ? 0 : min($total, self::days($start, $t));
        $moments = [];
        if ($t < $start) {
            $moments[] = 'planning';
        } elseif ($t > $end) {
            if (self::days($end, $t) - 1 <= self::CLOSING_DAYS) {
                $moments[] = 'closing';
            }
        } else {
            $endPhase = $total < 42 ? (int) ceil($total / 3) : self::END_PHASE_DAYS;
            $moments[] = self::days($t, $end) <= $endPhase ? 'end' : 'selling';
        }
        if ($t <= $end && $this->trips->current($today) !== null) {
            $moments[] = 'trip';
        }

        return ['moments' => $moments, 'daysTotal' => $total, 'daysRemaining' => $remaining, 'elapsedShare' => $total > 0 ? $elapsed / $total : 0.0, 'remainingFrom' => $remainingFrom];
    }

    /**
     * Tous les candidats de la période, classés (rang de départ = |effet| × poids de la confiance), plafonnés à 8 sauf trésorerie.
     *
     * @return array{moments: list<string>, advices: list<array<string, mixed>>}
     */
    public function detect(Collections $period, \DateTimeImmutable $today): array
    {
        $this->asOf = $today->format('Y-m-d');
        $m = $this->moments($period, $today);
        $has = fn (string ...$moments): bool => array_intersect($moments, $m['moments']) !== [];
        $todayKey = $today->format('Y-m-d');
        $c = [];
        $pace = null;
        $paceFor = function () use (&$pace, $today, $todayKey): array {
            return $pace ??= $this->simulation->soldByVariant($today->modify('-30 days')->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d'), $todayKey);
        };
        if ($has('selling')) {
            array_push($c, ...$this->fastSellers($period, $paceFor(), $m['daysRemaining']));
            array_push($c, ...$this->adRelaunch($today));
            array_push($c, ...$this->paceVsLastYear($period, $today));
        }
        if ($has('selling', 'end')) {
            array_push($c, ...$this->slowStock($period, $today));
        }
        if ($has('end')) {
            array_push($c, ...$this->endClearance($period, $paceFor(), $m['daysRemaining'], $todayKey));
        }
        if ($has('selling', 'end', 'trip', 'planning')) {
            array_push($c, ...$this->budgetPace($period, $m));
        }
        if ($has('trip')) {
            $trip = $this->trips->current($today);
            if ($trip !== null) {
                array_push($c, ...$this->tripBudget($trip, $today), ...$this->tripCoefficient($trip, $today));
            }
        }
        if ($has('selling', 'end', 'trip', 'planning')) {
            array_push($c, ...$this->cashTight($today));
        }
        if ($has('selling', 'trip', 'planning')) {
            array_push($c, ...$this->purchaseCapacity($period, $today));
        }
        if ($has('closing')) {
            array_push($c, ...$this->closing($period, $todayKey));
        }

        foreach ($c as &$a) {
            $a['pinned'] = in_array($a['kind'], self::PINNED, true);
            if (!in_array($a['moment'], $m['moments'], true)) { // règle commune à plusieurs moments : celui du jour
                $a['moment'] = $m['moments'][0] ?? $a['moment'];
            }
            $a['score'] = abs($a['effect']['amount']) * self::WEIGHTS[$a['confidence']];
        }
        unset($a);
        usort($c, fn ($x, $y) => [$y['pinned'], $y['score']] <=> [$x['pinned'], $x['score']]);
        $kept = [];
        foreach ($c as $a) {
            if ($a['pinned'] || count(array_filter($kept, fn ($k) => !$k['pinned'])) < self::MAX_ADVICES) {
                $kept[] = $a;
            }
        }
        usort($kept, fn ($x, $y) => $y['score'] <=> $x['score']);

        return ['moments' => $m['moments'], 'advices' => $kept];
    }

    // ----- R1 à R11 -----

    /** R1 : article vendu au moins 5 fois en net sur 30 jours, moins de 21 jours de stock, coût connu → +5 % */
    private function fastSellers(Collections $period, array $pace, int $daysRemaining): array
    {
        if ($daysRemaining <= 0) {
            return [];
        }
        $byProduct = [];
        foreach ($pace as $vid => $v) {
            $p = &$byProduct[$v['productId']];
            $p['qty'] = ($p['qty'] ?? 0) + $v['qty'];
            $p['stock'] = ($p['stock'] ?? 0) + $v['stock'];
            $p['cost'] = $v['cost'];
            $p['net'] = ($p['net'] ?? 0.0) + $v['qty'] * $v['unitPrice'];
            $p['projected'] = ($p['projected'] ?? 0.0) + min($v['qty'] * $daysRemaining / 30, (float) $v['stock']);
            $p['effect'] = ($p['effect'] ?? 0.0) + min($v['qty'] * $daysRemaining / 30, (float) $v['stock']) * $v['unitPrice'] * self::FAST_PRICE_PERCENT / 100;
            unset($p);
        }
        $onPromotion = $this->promoted(false); // lot P5 : pas de hausse du prix normal pendant une promotion
        $out = [];
        foreach ($byProduct as $pid => $p) {
            if (isset($onPromotion[$pid])) {
                continue;
            }
            if ($p['qty'] < self::FAST_MIN_SOLD || $p['cost'] === null || $p['stock'] * 30 >= self::FAST_MAX_COVERAGE_DAYS * $p['qty'] || round($p['effect']) <= 0) {
                continue;
            }
            $info = $this->products([$pid])[$pid] ?? null;
            if ($info === null) {
                continue;
            }
            $coverage = (int) floor($p['stock'] * 30 / $p['qty']);
            $out[] = $this->advice('FAST_SELLER', 'selling', "FAST_SELLER:p$pid", ['productIds' => [$pid]], ['screen' => 'articles', 'params' => ['productIds' => [$pid]]],
                (int) round($p['effect']), 'extra_margin', $p['qty'] >= self::FAST_MEDIUM_FROM ? 'medium' : 'low', [
                    $this->src('sold_30_days', $p['qty'], 'count'), $this->src('stock', $p['stock'], 'count'), $this->src('coverage_days', $coverage, 'days'),
                    $this->src('average_price', (int) round($p['net'] / $p['qty']), 'money'), $this->src('price_increase', self::FAST_PRICE_PERCENT, 'percent'),
                    $this->src('projected_units', (int) round($p['projected']), 'count'),
                ], [$info + ['stock' => $p['stock'], 'soldLast30Days' => $p['qty']]], ['name' => $info['name']]);
        }

        return $this->top($out);
    }

    /** R2 : article en stock sans vente nette depuis 45 jours, valeur au coût ≥ 100 $, plus vieux que 45 jours → promotion 20 % */
    private function slowStock(Collections $period, \DateTimeImmutable $today): array
    {
        $conn = $this->conn();
        [$since] = [$today->modify('-' . self::SLOW_DAYS . ' days')->format('Y-m-d')];
        $rows = $conn->fetchAllAssociative(
            'WITH st AS (SELECT pv.product_id AS pid, SUM(GREATEST(COALESCE(pv.stock_quantity, 0), 0)) AS stock FROM product_variant pv GROUP BY pv.product_id),'
            . ' sold AS (SELECT pv.product_id AS pid, SUM(CASE WHEN o.order_type_id = 2 THEN -oi.quantity ELSE oi.quantity END) AS qty, MAX(o.order_date) AS last_sale'
            . ' FROM order_items oi JOIN "order" o ON o.id = oi.order_associated_id JOIN product_variant pv ON pv.id = oi.product_variant_id'
            . ' WHERE o.order_type_id IN (1, 2) AND o.status_id NOT IN (1, 7) AND o.order_date <= :now GROUP BY pv.product_id)'
            . ' SELECT p.id, st.stock, sold.last_sale,'
            . ' COALESCE((SELECT MIN(CAST(lc.date AS date)) FROM commande_line l JOIN commande lc ON lc.id = l.commande_id WHERE l.product_id = p.id), CAST(p.created_at AS date)) AS since,'
            . ' COALESCE((SELECT SUM(CASE WHEN o.order_type_id = 2 THEN -oi.quantity ELSE oi.quantity END) FROM order_items oi JOIN "order" o ON o.id = oi.order_associated_id JOIN product_variant pv ON pv.id = oi.product_variant_id'
            . "   WHERE pv.product_id = p.id AND o.order_type_id IN (1, 2) AND o.status_id NOT IN (1, 7) AND o.order_date >= :since AND o.order_date <= :now), 0) AS recent,"
            . ' ' . $this->costSql() . ' AS cost'
            . " FROM product p JOIN st ON st.pid = p.id LEFT JOIN sold ON sold.pid = p.id WHERE p.product_type = 'standard' AND NOT COALESCE(p.rental_enabled, false) AND st.stock > 0",
            ['since' => $since, 'now' => $today->modify('+1 day')->format('Y-m-d'), 'today' => $today->format('Y-m-d')]
        );
        $promoted = $this->promoted(true); // lot P5 : pas de nouvelle promotion pour un article qui en a déjà une, en cours ou à venir
        $out = [];
        foreach ($rows as $r) {
            if (isset($promoted[(int) $r['id']])) {
                continue;
            }
            $value = (int) round((int) $r['stock'] * (float) ($r['cost'] ?? 0));
            if ((int) $r['recent'] > 0 || $r['cost'] === null || $value < self::SLOW_MIN_COST_VALUE || $r['since'] === null || substr((string) $r['since'], 0, 10) > $since) {
                continue;
            }
            $pid = (int) $r['id'];
            $last = $r['last_sale'] !== null ? substr((string) $r['last_sale'], 0, 10) : substr((string) $r['since'], 0, 10);
            $idle = self::days($last, $today->format('Y-m-d')) - 1;
            $info = $this->products([$pid])[$pid];
            $out[] = $this->advice('SLOW_STOCK', 'selling', "SLOW_STOCK:p$pid", ['productIds' => [$pid]],
                ['screen' => 'simulateur', 'params' => ['periodId' => (int) $period->getId(), 'promotion' => ['percent' => self::PROMOTION_PERCENT, 'scope' => 'products', 'productIds' => [$pid]]]],
                $value, 'tied_up', 'low', [
                    $this->src('stock', (int) $r['stock'], 'count'), $this->src('cost_value', $value, 'money'), $this->src('days_without_sale', $idle, 'days'),
                    $this->src('promotion', self::PROMOTION_PERCENT, 'percent'),
                ], [$info + ['stock' => (int) $r['stock'], 'soldLast30Days' => 0]], ['name' => $info['name']]);
        }

        return $this->top($out);
    }

    /** R3 : campagne terminée depuis moins de 60 jours, gain de marge pour 1 $ ≥ 2, aucune campagne en cours */
    private function adRelaunch(\DateTimeImmutable $today): array
    {
        $conn = $this->conn();
        $t = $today->format('Y-m-d');
        if ($conn->fetchOne('SELECT 1 FROM marketing_campaign WHERE start_date <= :t AND end_date >= :t LIMIT 1', ['t' => $t]) !== false) {
            return [];
        }
        $ids = $conn->fetchFirstColumn('SELECT id FROM marketing_campaign WHERE end_date < :t AND end_date >= :from ORDER BY end_date DESC LIMIT 10', ['t' => $t, 'from' => $today->modify('-' . self::AD_ENDED_WITHIN_DAYS . ' days')->format('Y-m-d')]);
        $out = [];
        foreach ($ids as $id) {
            $campaign = $this->emProvider->getEntityManager()->find(MarketingCampaign::class, (int) $id);
            if ($campaign === null) {
                continue;
            }
            $p = $this->campaigns->compute($campaign);
            $gain = $p['gainPerDollar']['grossMargin'] ?? null;
            if ($gain === null || $gain < self::AD_MIN_GAIN) {
                continue;
            }
            $amount = (int) $campaign->amountSite();
            $out[] = $this->advice('AD_RELAUNCH', 'selling', "AD_RELAUNCH:c$id", ['campaignId' => (int) $id], ['screen' => 'campagnes.new', 'params' => ['fromCampaignId' => (int) $id]],
                (int) round(($gain - 1) * $amount), 'net_margin_same_spend', $p['caveats'] === [] ? 'medium' : 'low', [
                    $this->src('campaign_amount', $amount, 'money'), $this->src('gain_per_dollar', round($gain, 2), 'ratio'),
                    $this->src('margin_uplift', (int) ($p['uplift']['grossMargin'] ?? 0), 'money'), $this->src('campaign_end', $campaign->getEndDate()->format('Y-m-d'), 'date'),
                ], [], ['channel' => (string) $campaign->getChannel()]);
        }

        return $this->top($out, 2);
    }

    /** R4 : poste dépassé, ou réel ÷ prévu ≥ part du temps écoulé + 0,2 (dépassement projeté linéairement) */
    private function budgetPace(Collections $period, array $m): array
    {
        $budget = $this->budgets->get($period, 'fr');
        $out = [];
        foreach ($budget['lines'] as $l) {
            $planned = (int) $l['planned'];
            $actual = (int) $l['actual'];
            if ($planned <= 0) {
                continue;
            }
            $share = $m['elapsedShare'];
            if ($actual > $planned) {
                [$over, $confidence] = [$actual - $planned, 'high'];
            } elseif ($share > 0 && $actual / $planned >= $share + self::BUDGET_AHEAD && ($projected = (int) round($actual / $share) - $planned) > 0) {
                [$over, $confidence] = [$projected, 'medium'];
            } else {
                continue;
            }
            $out[] = $this->advice('BUDGET_PACE', 'selling', "BUDGET_PACE:{$l['category']}", ['category' => $l['category']], ['screen' => 'periode.budget', 'params' => ['periodId' => (int) $period->getId()]],
                -$over, $confidence === 'high' ? 'over_budget' : 'projected_overrun', $confidence, [
                    $this->src('planned', $planned, 'money'), $this->src('actual', $actual, 'money'), $this->src('elapsed_share', round($share * 100, 1), 'percent'), $this->src('overrun', $over, 'money'),
                ], [], ['category' => $l['category']]);
        }

        return $out;
    }

    /** R5 : voyage en cours au-delà de 80 % de son budget à date, ou dépassé */
    private function tripBudget(\App\Entity\GestionTrip $trip, \DateTimeImmutable $today): array
    {
        $f = $this->trips->follow($trip, $today);
        if (!in_array($f['status'], ['warning', 'over'], true)) {
            return [];
        }
        $daysLeft = max(1, self::days($today->format('Y-m-d'), $trip->getEndDate()->format('Y-m-d')));
        $remaining = (int) $f['totals']['remaining'];

        return [$this->advice('TRIP_BUDGET', 'trip', 'TRIP_BUDGET:t' . $trip->getId(), ['tripId' => (int) $trip->getId()], ['screen' => 'voyages.suivi', 'params' => ['tripId' => (int) $trip->getId()]],
            $remaining, $remaining < 0 ? 'over_budget' : 'trip_remaining', 'high', [
                $this->src('trip_budget', (int) $f['totals']['budget'], 'money'), $this->src('trip_spent', (int) $f['totals']['spent'], 'money'), $this->src('trip_remaining', $remaining, 'money'),
                $this->src('trip_days_left', $daysLeft, 'days'), $this->src('trip_daily_left', (int) floor(max(0, $remaining) / $daysLeft), 'money'),
            ], [], ['place' => (string) ($trip->getPlace() ?? '')])];
    }

    /** R6 : coefficient des achats du voyage < 0,9 × coefficient des achats des 365 derniers jours (au moins 20 lignes) */
    private function tripCoefficient(\App\Entity\GestionTrip $trip, \DateTimeImmutable $today): array
    {
        $sql = 'SELECT COUNT(*) AS n, COALESCE(SUM(l.quantity_ordered * COALESCE(l.unit_cost_cents, p.purchase_price) * COALESCE(c.exchange_rate, 1)), 0) AS cost,'
            . ' COALESCE(SUM(l.quantity_ordered * COALESCE(pv.price, p.price)), 0) AS sale'
            . ' FROM commande_line l JOIN commande c ON c.id = l.commande_id JOIN product p ON p.id = l.product_id LEFT JOIN product_variant pv ON pv.id = l.product_variant_id'
            . ' WHERE COALESCE(l.unit_cost_cents, p.purchase_price) > 0 AND COALESCE(pv.price, p.price) > 0 AND ';
        $conn = $this->conn();
        $t = $conn->fetchAssociative($sql . 'c.trip_id = :id', ['id' => $trip->getId()]);
        $r = $conn->fetchAssociative($sql . "(c.trip_id IS NULL OR c.trip_id <> :id) AND COALESCE(c.kind, '') <> 'reprise' AND c.date >= :from AND c.date < :to",
            ['id' => $trip->getId(), 'from' => $today->modify('-' . self::COEF_REFERENCE_DAYS . ' days')->format('Y-m-d'), 'to' => $today->modify('+1 day')->format('Y-m-d')]);
        if ((float) $t['cost'] <= 0 || (int) $r['n'] < self::COEF_MIN_LINES || (float) $r['cost'] <= 0) {
            return [];
        }
        $coef = round((float) $t['sale'] / (float) $t['cost'], 2);
        $ref = round((float) $r['sale'] / (float) $r['cost'], 2);
        if ($coef >= $ref * self::COEF_GAP) {
            return [];
        }
        $cost = (int) round((float) $t['cost']);

        return [$this->advice('TRIP_COEFFICIENT', 'trip', 'TRIP_COEFFICIENT:t' . $trip->getId(), ['tripId' => (int) $trip->getId()], ['screen' => 'voyages.suivi', 'params' => ['tripId' => (int) $trip->getId()]],
            (int) round($cost * ($ref - $coef)), 'extra_sale_value', 'medium', [
                $this->src('trip_coefficient', $coef, 'ratio'), $this->src('reference_coefficient', $ref, 'ratio'), $this->src('trip_purchases', $cost, 'money'),
                $this->src('expected_margin', (int) round((float) $t['sale']) - $cost, 'money'),
            ], [], ['place' => (string) ($trip->getPlace() ?? '')])];
    }

    /** R7 : trésorerie basse (statut warning ou critical de B1b-1) */
    private function cashTight(\DateTimeImmutable $today): array
    {
        $a = $this->cash->alert($today);
        if ($a === null || $a['status'] === 'ok') {
            return [];
        }

        return [$this->advice('CASH_TIGHT', 'selling', 'CASH_TIGHT', [], ['screen' => 'tresorerie', 'params' => new \stdClass()],
            $a['low14']['amount'] - $a['threshold'], 'cash_shortfall', $a['series'] === 'projectionWithSales' ? 'medium' : 'high', [
                $this->src('cash_low', $a['low14']['amount'], 'money'), $this->src('cash_low_date', $a['low14']['date'], 'date'), $this->src('cash_threshold', $a['threshold'], 'money'),
            ], [], [])];
    }

    /** R8 : reste à acheter du stock prévu non finançable aujourd'hui → premier jour possible dans les 90 jours */
    private function purchaseCapacity(Collections $period, \DateTimeImmutable $today): array
    {
        $line = array_values(array_filter($this->budgets->get($period, 'fr')['lines'], fn ($l) => $l['category'] === 'purchases'))[0] ?? null;
        $remaining = $line === null ? 0 : max(0, (int) $line['planned'] - (int) $line['actual']);
        if ($remaining <= 0) {
            return [];
        }
        $p = $this->cash->projection(self::CAPACITY_HORIZON_DAYS, 'fr', $today);
        if ($p['balance'] === null) {
            return [];
        }
        $series = $p[$p['alertSeries']]['days'];
        $todayKey = $today->format('Y-m-d');
        $days = array_values(array_filter($series, fn ($d) => $d['date'] >= $todayKey));
        $min = PHP_INT_MAX;
        $suffix = [];
        for ($i = count($days) - 1; $i >= 0; --$i) {
            $min = min($min, $days[$i]['balance']);
            $suffix[$i] = $min;
        }
        $first = null;
        foreach ($days as $i => $d) {
            if ($suffix[$i] - $remaining >= $p['settings']['threshold']) {
                $first = $d['date'];
                break;
            }
        }
        if ($first === $todayKey) {
            return [];
        }
        $sources = [$this->src('remaining_purchases', $remaining, 'money'), $this->src('cash_threshold', $p['settings']['threshold'], 'money')];
        $sources[] = $first === null ? $this->src('horizon_days', self::CAPACITY_HORIZON_DAYS, 'days') : $this->src('purchase_from', $first, 'date');

        return [$this->advice('PURCHASE_CAPACITY', 'selling', 'PURCHASE_CAPACITY', ['category' => 'purchases'], ['screen' => 'tresorerie', 'params' => new \stdClass()],
            -$remaining, 'purchases_on_hold', $p['alertSeries'] === 'projectionWithSales' ? 'medium' : 'high', $sources, [], ['date' => $first])];
    }

    /** R9 : stock de fin (au coût) ≥ 30 % des achats de la période (ou ≥ 500 $ sans achats) → promotion sur les 5 articles lents les plus lourds */
    private function endClearance(Collections $period, array $pace, int $daysRemaining, string $today): array
    {
        $byProduct = [];
        foreach ($this->simulation->stockByVariant($today) as $vid => $s) {
            $sold = $pace[$vid]['qty'] ?? 0;
            $left = max(0.0, $s['stock'] - min($sold * $daysRemaining / 30, (float) $s['stock']));
            $p = &$byProduct[$s['productId']];
            $p['value'] = ($p['value'] ?? 0.0) + $left * (float) ($s['cost'] ?? 0);
            $p['sold'] = ($p['sold'] ?? 0) + $sold;
            $p['stock'] = ($p['stock'] ?? 0) + $s['stock'];
            unset($p);
        }
        $total = (int) round(array_sum(array_column($byProduct, 'value')));
        $purchases = (int) (array_values(array_filter($this->budgets->get($period, 'fr')['lines'], fn ($l) => $l['category'] === 'purchases'))[0]['actual'] ?? 0);
        if ($purchases > 0 ? $total < self::CLEARANCE_SHARE * $purchases : $total < self::CLEARANCE_MIN) {
            return [];
        }
        $promoted = $this->promoted(true); // lot P5 : articles déjà en promotion (en cours ou à venir) exclus
        $slow = array_filter($byProduct, fn ($p, $pid) => $p['sold'] <= self::CLEARANCE_SLOW_MAX_SOLD && $p['value'] > 0 && !isset($promoted[$pid]), ARRAY_FILTER_USE_BOTH);
        uasort($slow, fn ($a, $b) => $b['value'] <=> $a['value']);
        $slow = array_slice($slow, 0, self::CLEARANCE_MAX_PRODUCTS, true);
        if ($slow === []) {
            return [];
        }
        $ids = array_keys($slow);
        $info = $this->products($ids);
        $value = (int) round(array_sum(array_column($slow, 'value')));

        return [$this->advice('END_CLEARANCE', 'end', 'END_CLEARANCE', ['productIds' => $ids],
            ['screen' => 'simulateur', 'params' => ['periodId' => (int) $period->getId(), 'promotion' => ['percent' => self::PROMOTION_PERCENT, 'scope' => 'products', 'productIds' => $ids]]],
            $value, 'cash_to_free', 'low', [
                $this->src('end_stock_value', $total, 'money'), $this->src('period_purchases', $purchases, 'money'), $this->src('targeted_value', $value, 'money'),
                $this->src('products', count($ids), 'count'), $this->src('promotion', self::PROMOTION_PERCENT, 'percent'),
            ], array_values(array_map(fn ($id) => ($info[$id] ?? ['productId' => $id, 'variantId' => null, 'name' => '']) + ['stock' => $slow[$id]['stock'], 'soldLast30Days' => $slow[$id]['sold']], $ids)), ['count' => count($ids)])];
    }

    /** R10 : ventes prévues de la période (base du simulateur) ÷ ventes de la même période l'an dernier < 0,85 ou > 1,15 */
    private function paceVsLastYear(Collections $period, \DateTimeImmutable $today): array
    {
        $s = $this->simulation->simulate($period, [], 'fr', $today);
        $ly = (int) ($s['lastYear']['revenue'] ?? 0);
        if ($ly <= 0) {
            return [];
        }
        $total = (int) $s['base']['sales']['total'];
        $ratio = $total / $ly;
        if ($ratio >= self::PACE_BEHIND && $ratio <= self::PACE_AHEAD) {
            return [];
        }
        $kind = $ratio < self::PACE_BEHIND ? 'PACE_BEHIND' : 'PACE_AHEAD';

        return [$this->advice($kind, 'selling', $kind, [], ['screen' => 'simulateur', 'params' => ['periodId' => (int) $period->getId()]],
            $total - $ly, $kind === 'PACE_BEHIND' ? 'sales_gap' : 'sales_lead', 'medium', [
                $this->src('expected_sales', $total, 'money'), $this->src('last_year_sales', $ly, 'money'), $this->src('sales_ratio', round($ratio * 100, 1), 'percent'),
            ], [], [])];
    }

    /** R11 : bilan (résultat net, an dernier) et leçons : rupture, invendus, poste dépassé de plus de 10 % */
    private function closing(Collections $period, string $today): array
    {
        $out = [];
        $result = $this->results->compute($period, null);
        $net = (int) $result['result']['net'];
        $sources = [$this->src('revenue', (int) $result['sales']['revenue'], 'money'), $this->src('gross_margin', (int) $result['result']['gross'], 'money'), $this->src('net_result', $net, 'money')];
        $lyPeriod = $this->calendar->findByDate(\DateTimeImmutable::createFromInterface($period->getStartDateCollection())->modify('-1 year'));
        if ($lyPeriod !== null && $lyPeriod->getId() !== $period->getId()) {
            $lyNet = (int) $this->results->compute($lyPeriod, null)['result']['net'];
            $sources[] = $this->src('last_year_net_result', $lyNet, 'money');
            $sources[] = $this->src('net_result_change', $net - $lyNet, 'money');
        }
        $out[] = $this->advice('CLOSING_REVIEW', 'closing', 'CLOSING_REVIEW', [], ['screen' => 'periode.budget', 'params' => ['periodId' => (int) $period->getId()]], $net, 'net_result', 'high', $sources, [], []);

        $start = $period->getStartDateCollection()->format('Y-m-d');
        $end = $period->getEndDateCollection()->format('Y-m-d');
        $sold = $this->simulation->soldByVariant($start, $end, $today);
        $stock = $this->simulation->stockByVariant($today);
        $outs = array_filter($sold, fn ($v, $vid) => $v['qty'] >= self::STOCKOUT_MIN_SOLD && $v['stock'] <= 0, ARRAY_FILTER_USE_BOTH);
        if ($outs !== []) {
            uasort($outs, fn ($a, $b) => $b['qty'] <=> $a['qty']);
            $outs = array_slice($outs, 0, self::LESSON_MAX_FIGURES, true);
            $pids = array_values(array_unique(array_column($outs, 'productId')));
            $margin = (int) round(array_sum(array_map(fn ($v) => $v['cost'] === null ? 0 : $v['qty'] * ($v['unitPrice'] - $v['cost']), $outs)));
            $info = $this->products($pids);
            $out[] = $this->advice('LESSON_STOCKOUT', 'closing', 'LESSON_STOCKOUT', ['productIds' => $pids], ['screen' => 'articles', 'params' => ['productIds' => $pids]],
                $margin, 'margin_of_sold_out', 'medium', [$this->src('sold_out_products', count($pids), 'count'), $this->src('units_sold', array_sum(array_column($outs, 'qty')), 'count'), $this->src('margin', $margin, 'money')],
                array_map(fn ($pid) => $info[$pid] + ['stock' => 0, 'soldLast30Days' => null], $pids), ['count' => count($pids)]);
        }
        $unsold = [];
        foreach ($stock as $vid => $s) {
            if (!isset($sold[$vid]) && $s['cost'] !== null) {
                $unsold[$s['productId']] = ($unsold[$s['productId']] ?? 0) + $s['stock'] * $s['cost'];
            }
        }
        arsort($unsold);
        $unsold = array_slice($unsold, 0, self::LESSON_MAX_FIGURES, true);
        $unsoldValue = (int) round(array_sum($unsold));
        if ($unsoldValue >= self::SLOW_MIN_COST_VALUE) {
            $pids = array_keys($unsold);
            $info = $this->products($pids);
            $out[] = $this->advice('LESSON_OVERSTOCK', 'closing', 'LESSON_OVERSTOCK', ['productIds' => $pids], ['screen' => 'articles', 'params' => ['productIds' => $pids]],
                $unsoldValue, 'tied_up', 'medium', [$this->src('unsold_products', count($pids), 'count'), $this->src('cost_value', $unsoldValue, 'money')],
                array_map(fn ($pid) => $info[$pid] + ['stock' => null, 'soldLast30Days' => null], $pids), ['count' => count($pids)]);
        }
        $budget = $this->budgets->get($period, 'fr');
        foreach ($budget['lines'] as $l) {
            if ($l['planned'] > 0 && $l['actual'] > $l['planned'] * (1 + self::LESSON_BUDGET_OVER)) {
                $out[] = $this->advice('LESSON_BUDGET', 'closing', "LESSON_BUDGET:{$l['category']}", ['category' => $l['category']], ['screen' => 'periode.budget', 'params' => ['periodId' => (int) $period->getId()]],
                    -((int) $l['actual'] - (int) $l['planned']), 'over_budget', 'high', [$this->src('planned', (int) $l['planned'], 'money'), $this->src('actual', (int) $l['actual'], 'money'),
                        $this->src('overrun', (int) $l['actual'] - (int) $l['planned'], 'money')], [], ['category' => $l['category']]);
            }
        }

        return $out;
    }

    // ----- interne -----

    /**
     * Articles en promotion le jour de calcul (lot P5), et à venir si `$planned` : prix promotionnel posé, dont la fin n'est pas passée au début du
     * jour du magasin (et, sans `$planned`, dont le début est avant la fin de ce jour). Instants comparés en UTC, comme `Product::getEffectivePrice`.
     *
     * @return array<int, true>
     */
    private function promoted(bool $planned): array
    {
        $day = $this->asOf ?? (new \DateTimeImmutable())->format('Y-m-d');
        [$start, $end] = $this->clock !== null ? $this->clock->range($day, $day) : [new \DateTimeImmutable($day), (new \DateTimeImmutable($day))->modify('+1 day')];
        $sql = 'SELECT id FROM product WHERE special_price > 0 AND (special_price_to IS NULL OR special_price_to >= :start)' . ($planned ? '' : ' AND (special_price_from IS NULL OR special_price_from < :end)');

        return array_fill_keys(array_map('intval', $this->conn()->fetchFirstColumn($sql, ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')])), true);
    }

    private function advice(string $kind, string $moment, string $signature, array $subject, array $action, int $effect, string $effectLabel, string $confidence, array $sources, array $figures, array $params): array
    {
        return ['kind' => $kind, 'moment' => $moment, 'signature' => $signature, 'subject' => $subject, 'action' => $action, 'effect' => ['amount' => $effect, 'label' => $effectLabel],
            'confidence' => $confidence, 'sources' => $sources, 'figures' => $figures, 'params' => $params];
    }

    private function src(string $key, int|float|string $value, string $unit): array
    {
        return ['key' => $key, 'value' => $value, 'unit' => $unit];
    }

    /** @return list<array<string, mixed>> les `$n` plus gros effets */
    private function top(array $advices, int $n = self::MAX_PER_RULE): array
    {
        usort($advices, fn ($a, $b) => abs($b['effect']['amount']) <=> abs($a['effect']['amount']));

        return array_slice($advices, 0, $n);
    }

    /** @return array<int, array{productId: int, variantId: null, name: string, price: int, cost: ?int}> */
    private function products(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->conn()->fetchAllAssociative('SELECT p.id, p.name, COALESCE(p.price, 0) AS price, ' . $this->costSql() . ' AS cost FROM product p WHERE p.id IN (:ids)',
            ['ids' => array_values($ids), 'today' => $this->asOf ?? (new \DateTimeImmutable())->format('Y-m-d')], ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = ['productId' => (int) $r['id'], 'variantId' => null, 'name' => (string) $r['name'], 'price' => (int) $r['price'], 'cost' => $r['cost'] === null ? null : (int) round((float) $r['cost'])];
        }

        return $out;
    }

    /** Coût unitaire courant (même règle que le simulateur) ; paramètre `:today` */
    private function costSql(): string
    {
        return 'COALESCE((SELECT COALESCE(SUM(l.quantity_ordered * l.unit_cost_cents * COALESCE(lc.exchange_rate, 1)) / NULLIF(SUM(l.quantity_ordered), 0), AVG(l.unit_cost_cents * COALESCE(lc.exchange_rate, 1)))'
            . ' FROM commande_line l JOIN commande lc ON lc.id = l.commande_id WHERE l.product_id = p.id AND l.unit_cost_cents IS NOT NULL AND CAST(lc.date AS date) <= CAST(:today AS date)), p.purchase_price)';
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }

    public static function days(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a') + 1;
    }
}
