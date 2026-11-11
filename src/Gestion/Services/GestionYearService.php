<?php

namespace App\Gestion\Services;

use App\Entity\Collections;
use App\Entity\PeriodResult;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * L'année de l'entreprise (lot AN, 11/11/2026, `docs/annee-gestion.md`, contrat validé par l'application) : suivre l'année mois par mois et
 * savoir si l'entreprise est rentable, et ce qui lui manque. Aucune deuxième règle : ventes et marge par `PeriodResultCalculator::dailySales`
 * (règles de P2), frais variables à leur date comme P2 (port d'achat, publicité, notes de frais hors voyage, notes de voyage), frais fixes au
 * prorata (FF1), autres revenus (locations, abonnements). Résultat de l'entreprise = marge + autres revenus − frais variables − frais fixes.
 * Année civile, fuseau du magasin, cents. Lecture seule, sauf le cache de l'avis de l'IA (année terminée).
 */
class GestionYearService
{
    public const MIN_YEAR = 2000;
    public const DEFAULT_PRICE_PERCENT = 5;
    public const PACE_SALES_DAYS = 30;
    public const PACE_COSTS_DAYS = 90;
    public const FIXED_CUT = 0.10;
    private const COST_KEYS = ['shipping', 'advertising', 'notes', 'trip'];
    private const BUDGET_TO_COST = ['shipping' => 'shipping', 'advertising' => 'advertising', 'other' => 'notes', 'trip' => 'trip'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodResultCalculator $results,
        private readonly PeriodCalendar $calendar,
        private readonly GestionFixedCostService $fixedCosts,
        private readonly GestionSimulationCommentService $comments,
        private readonly GestionAdviceService $advices,
        private readonly GestionFiscalYearService $fiscal,
        private readonly TranslatorInterface $translator,
        private readonly ?TenantCurrencyProvider $currency = null,
    ) {
    }

    /** GET /api/gestion/statistiques/annee?year=&pricePercent=&locale= */
    public function overview(array $query, string $locale, ?\DateTimeImmutable $today = null): array
    {
        $today ??= $this->calendar->today();
        $t = $today->format('Y-m-d');
        $sm = $this->fiscal->startMonth(); // lot AN partie EX : exercice du site, désigné par son année de début
        $current = GestionFiscalYearService::startYearOf($today, $sm);
        $year = $this->year($query['year'] ?? null, $current);
        $pct = $query['pricePercent'] ?? null;
        if ($pct === null || $pct === '') {
            $pct = self::DEFAULT_PRICE_PERCENT;
        } elseif (!is_numeric($pct) || (int) $pct != $pct || $pct < 1 || $pct > 30) {
            throw new GestionValidationException(['pricePercent' => 'OUT_OF_RANGE']);
        }
        $pct = (int) $pct;
        [$jan, $dec] = GestionFiscalYearService::bounds($year, $sm); // premier et dernier jour de l'exercice
        [$lyJan, $lyDec] = GestionFiscalYearService::bounds($year - 1, $sm);
        $finished = $dec < $t;
        $end = $finished ? $dec : $t; // dernier jour réalisé

        $sales = $this->results->dailySales($jan, $dec);
        $salesLy = $this->results->dailySales($lyJan, $lyDec);
        $costs = $this->variableByDay($lyJan, $dec);

        $months = [];
        $ly = [];
        for ($i = 0; $i < 12; $i++) { // dans l'ordre de l'exercice
            $start = (new \DateTimeImmutable($jan))->modify("+$i months");
            $m = (int) $start->format('n');
            $from = $start->format('Y-m-d');
            $to = $start->modify('last day of this month')->format('Y-m-d');
            $elapsed = $to <= $end ? true : ($from <= $end ? 'partial' : false);
            $months[] = $elapsed === false ? ['month' => $m, 'year' => (int) $start->format('Y'), 'from' => $from, 'to' => $to, 'elapsed' => false] + array_fill_keys(['sales', 'grossMargin', 'grossMarginRate', 'otherRevenue', 'variableCosts', 'fixedCosts', 'result'], null)
                : ['month' => $m, 'year' => (int) $start->format('Y'), 'from' => $from, 'to' => $to, 'elapsed' => $elapsed] + $this->block($sales, $costs, $from, min($to, $end));
            $lyStart = (new \DateTimeImmutable($lyJan))->modify("+$i months");
            $ly[] = ['month' => $m, 'year' => (int) $lyStart->format('Y'), 'from' => $lyStart->format('Y-m-d'), 'to' => $lyStart->modify('last day of this month')->format('Y-m-d'), 'elapsed' => true]
                + $this->block($salesLy, $costs, $lyStart->format('Y-m-d'), $lyStart->modify('last day of this month')->format('Y-m-d'));
        }
        $hasLy = array_sum(array_map(fn ($b) => abs($b['sales']) + $b['variableCosts']['total'] + abs($b['otherRevenue']['total']), $ly)) > 0;
        $totals = $this->block($sales, $costs, $jan, $end);
        $periods = $this->periods($jan, $dec, $t);
        $unit = $totals['tauxMarque'];

        // Projection de l'année entière (année en cours) : réalisé + reste estimé, jour par jour
        $projection = null;
        $remainingSales = 0;
        if (!$finished) {
            $projection = $this->project($today, $dec, $lyJan, $lyDec, $sales, $salesLy, $costs, $totals);
            $remainingSales = $projection['remaining']['sales'];
        }
        $basis = $projection ?? $totals;
        $taux = $unit;
        $breakEven = null;
        if ($taux !== null && $taux > 0) {
            $needed = (int) ceil(max(0, $basis['variableCosts']['total'] + $basis['fixedCosts'] - $basis['otherRevenue']['total']) / ($taux / 100));
            $breakEven = ['sales' => $needed, 'reached' => $basis['sales'] >= $needed];
        }
        $gap = null;
        if ($basis['result'] < 0) {
            $margin = -$basis['result'];
            $gapSales = $taux !== null && $taux > 0 ? (int) ceil($margin / ($taux / 100)) : null;
            $remainingDays = $finished ? 0 : (int) $today->diff(new \DateTimeImmutable($dec))->format('%a');
            $rm = $remainingDays * 12 / ((int) (new \DateTimeImmutable($jan))->diff(new \DateTimeImmutable($dec))->format('%a') + 1);
            $gap = ['margin' => $margin, 'sales' => $gapSales,
                'perRemainingMonth' => $rm > 0 ? ['margin' => (int) round($margin / $rm), 'sales' => $gapSales === null ? null : (int) round($gapSales / $rm), 'remainingMonths' => round($rm, 2)] : null];
        }

        $caveats = [];
        if ($projection !== null) {
            $caveats[] = ['lastYear' => 'PROJECTION_LAST_YEAR', 'pace' => 'PROJECTION_PACE', 'none' => 'NO_PROJECTION'][$projection['method']];
            $caveats[] = 'PARTIAL_MONTH';
        }
        if ($totals['unknownCostRevenue'] != 0) {
            $caveats[] = 'UNKNOWN_COST';
        }
        if ($basis['fixedCosts'] > 0) {
            $caveats[] = 'FIXED_COSTS_PRORATED';
        }
        if (array_filter($periods, fn ($p) => $p['frozen'] && $p['netAfterFixed'] === null) !== []) {
            $caveats[] = 'PERIOD_FROZEN_BEFORE_FF1';
        }
        $aligned = $this->fiscal->alignedMonths();
        if (array_filter($periods, fn ($p) => $p['partial']) !== []) { // seulement quand une période de l'exercice est réellement à cheval
            $caveats[] = 'FISCAL_YEAR_NOT_ALIGNED_WITH_PERIODS';
        }

        $out = [
            'currency' => $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT,
            'year' => $year, 'status' => $finished ? 'finished' : 'in_progress', 'today' => $t,
            'fiscalYear' => ['startMonth' => $sm, 'from' => $jan, 'to' => $dec, 'label' => GestionFiscalYearService::label($year, $sm)],
            'months' => array_map(fn ($b) => self::publicBlock($b), $months),
            'cumulative' => self::cumulative($months),
            'lastYear' => $hasLy ? ['year' => $year - 1, 'label' => GestionFiscalYearService::label($year - 1, $sm), 'from' => $lyJan, 'to' => $lyDec, 'months' => array_map(fn ($b) => self::publicBlock($b), $ly), 'cumulative' => self::cumulative($ly)] : null,
            'periods' => $periods,
            'totals' => self::publicBlock($totals) + ['from' => $jan, 'to' => $end],
            'profitability' => [
                'tauxMarque' => $taux,
                'projection' => $projection === null ? null : self::publicBlock($projection) + ['method' => $projection['method'], 'reason' => $this->translator->trans('year.projection.' . $projection['method'], [], 'gestion', $locale), 'months' => $projection['months']],
                'breakEven' => $breakEven,
                'gap' => $gap,
            ],
            'levers' => $finished ? null : $this->levers($today, $pct, $remainingSales, $projection, $gap, $periods, $months, $locale),
            'finished' => $finished ? $this->finishedBlock($totals, $months, $periods, $breakEven) : null,
            'caveats' => array_map(fn ($c) => ['code' => $c, 'text' => $this->translator->trans("year.caveats.$c", ['%months%' => implode(', ', array_map(fn ($m) => $this->monthName($m, $locale), $aligned))], 'gestion', $locale)]
                + ($c === 'FISCAL_YEAR_NOT_ALIGNED_WITH_PERIODS' ? ['alignedMonths' => $aligned] : []), array_values(array_unique($caveats))),
        ];

        return $out;
    }

    /** POST /api/gestion/statistiques/annee/avis : avis de l'IA sur une année terminée (règles de G1 et P6 : chiffres vérifiés, cache, plafond partagé) */
    public function advise(array $query, string $locale, ?string $user, ?\DateTimeImmutable $today = null): array
    {
        $today ??= $this->calendar->today();
        $o = $this->overview($query, $locale, $today);
        if ($o['status'] !== 'finished') {
            throw new GestionConflictException('YEAR_NOT_FINISHED', 'L\'avis de l\'année se demande une fois l\'année terminée ; en cours d\'année, voyez les leviers chiffrés et le guide de la période.', 409, ['year' => $o['year']]);
        }
        $f = $o['finished'];
        $money = fn (?int $v) => $v === null ? null : $this->advices->format($v, 'money', $locale);
        $fallback = $this->fallbackComment($o, $locale);
        $numbers = [];
        $add = function (mixed $v, string $unit) use (&$numbers): void {
            if ($v !== null) {
                $numbers[] = ['value' => $v, 'unit' => $unit];
            }
        };
        foreach (['result', 'resultBeforeOtherRevenue', 'sales', 'grossMargin', 'fixedCosts'] as $k) {
            $add($o['totals'][$k], 'money');
        }
        $add($o['totals']['otherRevenue']['total'], 'money');
        $add($o['totals']['variableCosts']['total'], 'money');
        $add($o['profitability']['tauxMarque'], 'percent');
        $add($o['profitability']['breakEven']['sales'] ?? null, 'money');
        foreach (array_merge($f['bestMonths'], $f['worstMonths']) as $m) {
            $add($m['result'], 'money');
            $add($o['year'] . '-' . sprintf('%02d', $m['month']) . '-01', 'date');
        }
        foreach (['priceRequiredPercent' => 'percent', 'fixedCostsCutRequired' => 'money', 'salesRequired' => 'money'] as $k => $u) {
            $add($f['hindsight'][$k], $u);
        }
        $payload = [
            'year' => $o['fiscalYear']['label'], 'profitable' => $f['bilan']['profitable'],
            'result' => $money($o['totals']['result']), 'sales' => $money($o['totals']['sales']), 'grossMargin' => $money($o['totals']['grossMargin']),
            'tauxMarque' => $o['profitability']['tauxMarque'] === null ? null : $this->advices->format($o['profitability']['tauxMarque'], 'percent', $locale),
            'otherRevenue' => $money($o['totals']['otherRevenue']['total']), 'variableCosts' => $money($o['totals']['variableCosts']['total']), 'fixedCosts' => $money($o['totals']['fixedCosts']),
            'breakEvenSales' => $money($o['profitability']['breakEven']['sales'] ?? null),
            'bestMonths' => array_map(fn ($m) => ['month' => $this->monthName($m['month'], $locale), 'result' => $money($m['result'])], $f['bestMonths']),
            'worstMonths' => array_map(fn ($m) => ['month' => $this->monthName($m['month'], $locale), 'result' => $money($m['result'])], $f['worstMonths']),
            'hindsight' => ['priceRequired' => $f['hindsight']['priceRequiredPercent'] === null ? null : $this->advices->format($f['hindsight']['priceRequiredPercent'], 'percent', $locale),
                'fixedCostsCutRequired' => $money($f['hindsight']['fixedCostsCutRequired']), 'salesRequired' => $money($f['hindsight']['salesRequired'])],
            'fallback' => $fallback,
        ];
        $period = $this->cachePeriod($o['fiscalYear']['from'], $o['fiscalYear']['to']);
        if ($period === null) { // aucune période de gestion cette année-là : pas de cache possible, donc pas d'IA (le plafond ne pourrait pas compter)
            return ['year' => $o['year'], 'comment' => $fallback, 'ai' => ['status' => 'rules_only'], 'cached' => false];
        }
        $hash = self::cacheKey($o['year'], $locale, $numbers, $o['fiscalYear']['startMonth']);
        $day = $today->format('Y-m-d');
        $cached = $this->comments->cached($period, $hash, $day);
        if ($cached !== null && ($cached['kind'] ?? null) === 'year' && ($cached['year'] ?? null) === $o['year']) {
            return array_diff_key($cached, ['kind' => 1]) + ['cached' => true];
        }
        if (!$this->comments->underLimit($day)) {
            throw new GestionConflictException('COMMENT_LIMIT', sprintf('%d avis du guide par jour au plus : voici l\'avis calculé sans l\'IA.', GestionSimulationCommentService::PER_DAY), 429,
                ['year' => $o['year'], 'comment' => $fallback, 'ai' => ['status' => 'rules_only']]);
        }
        [$comment, $status, $usageId] = $this->comments->askGuide($period, $payload, $numbers, ['year'], $fallback, $locale, $user, $this->system($locale), true);
        unset($comment['recommended']);
        $response = ['year' => $o['year'], 'comment' => $comment, 'ai' => ['status' => $status]];
        $this->comments->store($period, $hash, $day, $response + ['kind' => 'year'], $status, $usageId, $user);

        return $response + ['cached' => false];
    }

    /** Clé du cache de l'avis de l'année : préfixe « year: » (jamais celle d'un avis du simulateur G1b-2 ou de la préparation P6, « prep: ») */
    public static function cacheKey(int $year, string $locale, array $numbers, int $startMonth = 1): string
    {
        return hash('sha256', 'year:' . json_encode([$year, $startMonth, $locale, $numbers]));
    }

    // ----- calculs -----

    /** @return array<string, mixed> bloc d'une plage [from, to] : ventes, marge, autres revenus, frais, résultat */
    private function block(array $sales, array $costs, string $from, string $to): array
    {
        $s = ['sales_amount' => 0.0, 'returns_amount' => 0.0, 'unknown_revenue' => 0.0, 'cogs' => 0.0, 'rentals' => 0.0, 'subscriptions' => 0.0];
        foreach ($sales as $day => $r) {
            if ($day >= $from && $day <= $to) {
                foreach ($s as $k => $_) {
                    $s[$k] += (float) ($r[$k] ?? 0);
                }
            }
        }
        $c = array_fill_keys(self::COST_KEYS, 0);
        foreach ($costs as $day => $r) {
            if ($day >= $from && $day <= $to) {
                foreach (self::COST_KEYS as $k) {
                    $c[$k] += $r[$k];
                }
            }
        }
        $revenue = (int) round($s['sales_amount'] - $s['returns_amount']);
        $unknown = (int) round($s['unknown_revenue']);
        $gross = $revenue - $unknown - (int) round($s['cogs']);
        $rentals = (int) round($s['rentals']);
        $subs = (int) round($s['subscriptions']);
        $fixed = $from <= $to ? $this->fixedCosts->between($from, $to)['total'] : 0;
        $c = array_map('intval', $c);
        $c['total'] = array_sum($c);

        return self::compose($revenue, $gross, $unknown, ['rentals' => $rentals, 'subscriptions' => $subs], $c, $fixed);
    }

    private static function compose(int $sales, int $gross, int $unknown, array $other, array $costs, int $fixed): array
    {
        $other['total'] = $other['rentals'] + $other['subscriptions'];
        $effective = $sales - $unknown;
        $before = $gross - $costs['total'] - $fixed;

        return [
            'sales' => $sales, 'grossMargin' => $gross, 'unknownCostRevenue' => $unknown,
            'grossMarginRate' => $effective > 0 ? round($gross / $effective * 100, 1) : null, 'tauxMarque' => $effective > 0 ? round($gross / $effective * 100, 1) : null,
            'otherRevenue' => $other, 'variableCosts' => $costs, 'fixedCosts' => $fixed,
            'resultBeforeOtherRevenue' => $before, 'result' => $before + $other['total'],
        ];
    }

    /** Champs rendus d'un bloc (sans les champs internes) */
    private static function publicBlock(array $b): array
    {
        return array_diff_key($b, ['tauxMarque' => 1, 'remaining' => 1, 'method' => 1, 'months' => 1, 'unknownCostRevenue' => 1]) + (isset($b['unknownCostRevenue']) ? ['unknownCostRevenue' => $b['unknownCostRevenue']] : []);
    }

    /** Cumul depuis janvier, mois écoulés seulement (null ensuite) */
    private static function cumulative(array $months): array
    {
        $out = [];
        $run = null;
        foreach ($months as $b) {
            if ($b['elapsed'] === false) {
                $out[] = ['month' => $b['month'], 'sales' => null, 'grossMargin' => null, 'otherRevenue' => null, 'variableCosts' => null, 'fixedCosts' => null, 'result' => null];

                continue;
            }
            $run = $run === null ? ['sales' => 0, 'grossMargin' => 0, 'otherRevenue' => 0, 'variableCosts' => 0, 'fixedCosts' => 0, 'result' => 0] : $run;
            $run['sales'] += $b['sales'];
            $run['grossMargin'] += $b['grossMargin'];
            $run['otherRevenue'] += $b['otherRevenue']['total'];
            $run['variableCosts'] += $b['variableCosts']['total'];
            $run['fixedCosts'] += $b['fixedCosts'];
            $run['result'] += $b['result'];
            $out[] = ['month' => $b['month']] + $run;
        }

        return $out;
    }

    /**
     * Reste de l'exercice, jour par jour du lendemain au dernier jour de l'exercice. Ventes, marge et autres revenus : l'an dernier aux mêmes dates s'il y a des
     * ventes (la saison), sinon le rythme des 30 derniers jours, au taux de marque de l'année. Frais variables : budget prévu (B1a) des périodes
     * qui couvrent le jour, sinon le même mois l'an dernier s'il a des frais, sinon le rythme des 90 derniers jours. Frais fixes : FF1.
     */
    private function project(\DateTimeImmutable $today, string $dec, string $lyJan, string $lyDec, array $sales, array $salesLy, array $costs, array $realized): array
    {
        $t = $today->format('Y-m-d');
        $start = $today->modify('+1 day')->format('Y-m-d');
        $taux = $realized['tauxMarque'];
        $day = fn (array $rows, string $d, string $k) => (float) ($rows[$d][$k] ?? 0);
        $lySales = 0.0;
        if ($start <= $dec) {
            foreach ($salesLy as $d => $r) {
                if ($d >= GestionStatisticsService::shiftYears($start, -1) && $d <= GestionStatisticsService::shiftYears($dec, -1)) {
                    $lySales += (float) $r['sales_amount'] - (float) $r['returns_amount'];
                }
            }
        }
        $paceFrom = $today->modify('-' . (self::PACE_SALES_DAYS - 1) . ' days')->format('Y-m-d');
        $pace = ['sales' => 0.0, 'rentals' => 0.0, 'subscriptions' => 0.0];
        foreach ($sales as $d => $r) {
            if ($d >= $paceFrom && $d <= $t) {
                $pace['sales'] += (float) $r['sales_amount'] - (float) $r['returns_amount'];
                $pace['rentals'] += (float) $r['rentals'];
                $pace['subscriptions'] += (float) $r['subscriptions'];
            }
        }
        $method = $lySales > 0 ? 'lastYear' : ($pace['sales'] > 0 ? 'pace' : 'none');
        $costPaceFrom = $today->modify('-' . (self::PACE_COSTS_DAYS - 1) . ' days')->format('Y-m-d');
        $costPace = array_fill_keys(self::COST_KEYS, 0.0);
        foreach ($costs as $d => $r) {
            if ($d >= $costPaceFrom && $d <= $t) {
                foreach (self::COST_KEYS as $k) {
                    $costPace[$k] += $r[$k] / self::PACE_COSTS_DAYS;
                }
            }
        }
        $budgets = $this->budgetDays($start, $dec);
        $lyMonthCosts = [];
        foreach ($costs as $d => $r) {
            if ($d >= $lyJan && $d <= $lyDec) { // exercice précédent
                $lyMonthCosts[(int) substr($d, 5, 2)] = ($lyMonthCosts[(int) substr($d, 5, 2)] ?? 0) + array_sum($r);
            }
        }

        $months = [];
        for ($d = new \DateTimeImmutable($start); ($k = $d->format('Y-m-d')) <= $dec; $d = $d->modify('+1 day')) {
            $m = (int) $d->format('n');
            $months[$m] ??= ['month' => $m, 'from' => $k, 'to' => $k, 'sales' => 0.0, 'grossMargin' => 0.0, 'rentals' => 0.0, 'subscriptions' => 0.0, 'costs' => array_fill_keys(self::COST_KEYS, 0.0), 'costDays' => ['budget' => 0, 'lastYear' => 0, 'pace' => 0]];
            $months[$m]['to'] = $k;
            $lyDay = GestionStatisticsService::shiftYears($k, -1);
            $s = match ($method) {
                'lastYear' => $day($salesLy, $lyDay, 'sales_amount') - $day($salesLy, $lyDay, 'returns_amount'),
                'pace' => $pace['sales'] / self::PACE_SALES_DAYS,
                default => 0.0,
            };
            $o = match ($method) {
                'lastYear' => [$day($salesLy, $lyDay, 'rentals'), $day($salesLy, $lyDay, 'subscriptions')],
                'pace' => [$pace['rentals'] / self::PACE_SALES_DAYS, $pace['subscriptions'] / self::PACE_SALES_DAYS],
                default => [0.0, 0.0],
            };
            if (isset($budgets[$k])) {
                $c = $budgets[$k];
                $src = 'budget';
            } elseif (($lyMonthCosts[$m] ?? 0) > 0) {
                $c = $costs[$lyDay] ?? array_fill_keys(self::COST_KEYS, 0);
                $src = 'lastYear';
            } else {
                $c = $costPace;
                $src = 'pace';
            }
            $months[$m]['costDays'][$src]++;
            $months[$m]['sales'] += $s;
            $months[$m]['grossMargin'] += $taux === null ? 0 : $s * $taux / 100;
            $months[$m]['rentals'] += $o[0];
            $months[$m]['subscriptions'] += $o[1];
            foreach (self::COST_KEYS as $ck) {
                $months[$m]['costs'][$ck] += $c[$ck];
            }
        }
        $outMonths = [];
        foreach ($months as $pm) {
            $costsInt = array_map(fn ($v) => (int) round($v), $pm['costs']);
            $costsInt['total'] = array_sum($costsInt);
            $fixed = $this->fixedCosts->between($pm['from'], $pm['to'])['total'];
            arsort($pm['costDays']);
            $b = self::compose((int) round($pm['sales']), (int) round($pm['grossMargin']), 0, ['rentals' => (int) round($pm['rentals']), 'subscriptions' => (int) round($pm['subscriptions'])], $costsInt, $fixed);
            $outMonths[] = ['month' => $pm['month'], 'from' => $pm['from'], 'to' => $pm['to']] + self::publicBlock($b)
                + ['sources' => ['sales' => $method, 'variableCosts' => array_key_first($pm['costDays'])]];
        }
        $sum = fn (string $k) => array_sum(array_column($outMonths, $k));
        $remCosts = array_fill_keys(self::COST_KEYS, 0);
        foreach ($outMonths as $om) {
            foreach (self::COST_KEYS as $ck) {
                $remCosts[$ck] += $om['variableCosts'][$ck];
            }
        }
        $other = ['rentals' => $realized['otherRevenue']['rentals'] + array_sum(array_map(fn ($om) => $om['otherRevenue']['rentals'], $outMonths)),
            'subscriptions' => $realized['otherRevenue']['subscriptions'] + array_sum(array_map(fn ($om) => $om['otherRevenue']['subscriptions'], $outMonths))];
        $allCosts = [];
        foreach (self::COST_KEYS as $ck) {
            $allCosts[$ck] = $realized['variableCosts'][$ck] + $remCosts[$ck];
        }
        $allCosts['total'] = array_sum($allCosts);
        $b = self::compose($realized['sales'] + $sum('sales'), $realized['grossMargin'] + $sum('grossMargin'), $realized['unknownCostRevenue'], $other, $allCosts, $realized['fixedCosts'] + $sum('fixedCosts'));

        return $b + ['method' => $method, 'months' => $outMonths, 'remaining' => ['sales' => (int) $sum('sales'), 'fixedCosts' => (int) $sum('fixedCosts'), 'from' => $start, 'to' => $dec]];
    }

    /** @return array<string, array<string, float>> jour => frais prévus par poste (budget B1a de la période qui le couvre, réparti sur ses jours) */
    private function budgetDays(string $from, string $to): array
    {
        $out = [];
        if ($from > $to) {
            return $out;
        }
        $rows = $this->conn()->fetchAllAssociative(
            "SELECT c.id, CAST(c.start_date_collection AS date) AS s, CAST(c.end_date_collection AS date) AS e, b.category, b.planned_cents FROM collections c JOIN gestion_budget_line b ON b.period_id = c.id"
            . " WHERE c.kind = 'period' AND c.del = false AND c.start_date_collection <= :to AND c.end_date_collection >= :from AND b.category IN ('shipping', 'advertising', 'other', 'trip') AND b.planned_cents > 0",
            ['from' => $from, 'to' => $to . ' 23:59:59']
        );
        foreach ($rows as $r) {
            $s = substr((string) $r['s'], 0, 10);
            $e = substr((string) $r['e'], 0, 10);
            $days = (int) (new \DateTimeImmutable($s))->diff(new \DateTimeImmutable($e))->format('%a') + 1;
            $daily = (int) $r['planned_cents'] / $days;
            for ($d = new \DateTimeImmutable(max($s, $from)); ($k = $d->format('Y-m-d')) <= min($e, $to); $d = $d->modify('+1 day')) {
                $out[$k] ??= array_fill_keys(self::COST_KEYS, 0.0);
                $out[$k][self::BUDGET_TO_COST[$r['category']]] += $daily;
            }
        }

        return $out;
    }

    /** @return array<string, array<string, int>> jour => frais variables à leur date (règles de P2) : port d'achat, publicité, notes hors voyage, notes de voyage */
    private function variableByDay(string $from, string $to): array
    {
        $conn = $this->conn();
        $p = ['from' => $from . ' 00:00:00', 'to' => (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00', 'd1' => $from, 'd2' => $to];
        $queries = [
            'shipping' => 'SELECT CAST(c.date AS date) AS d, SUM(f.price * COALESCE(c.exchange_rate, 1)) AS v FROM frais_de_port f JOIN commande c ON c.id = f.commande_id WHERE c.date >= :from AND c.date < :to GROUP BY 1',
            'advertising' => 'SELECT m.start_date AS d, SUM(ROUND(m.amount_cents * COALESCE(m.exchange_rate, 1))) AS v FROM marketing_campaign m WHERE m.start_date >= :d1 AND m.start_date <= :d2 GROUP BY 1',
            'notes' => 'SELECT n.date AS d, SUM(n.montant) AS v FROM note_de_frais n WHERE n.trip_id IS NULL AND n.date >= :d1 AND n.date <= :d2 GROUP BY 1',
            'trip' => 'SELECT n.date AS d, SUM(n.montant) AS v FROM note_de_frais n WHERE n.trip_id IS NOT NULL AND n.date >= :d1 AND n.date <= :d2 GROUP BY 1',
        ];
        $out = [];
        foreach ($queries as $k => $sql) {
            foreach ($conn->fetchAllAssociative($sql, $p) as $r) {
                $d = substr((string) $r['d'], 0, 10);
                $out[$d] ??= array_fill_keys(self::COST_KEYS, 0);
                $out[$d][$k] += (int) round((float) $r['v']);
            }
        }

        return $out;
    }

    /** Périodes de gestion qui recoupent l'année, avec leur résultat P2 (instantané pour une période fermée, sans rien écrire) */
    private function periods(string $jan, string $dec, string $today): array
    {
        $em = $this->emProvider->getEntityManager();
        $out = [];
        foreach ($this->conn()->fetchFirstColumn("SELECT id FROM collections WHERE kind = 'period' AND del = false AND start_date_collection <= ? AND end_date_collection >= ? ORDER BY start_date_collection, id", [$dec . ' 23:59:59', $jan]) as $id) {
            $p = $em->find(Collections::class, (int) $id);
            if ($p === null) {
                continue;
            }
            $frozen = false;
            $payload = null;
            if ($this->calendar->isClosed($p)) {
                $snap = $em->getRepository(PeriodResult::class)->findLatestByCollection($p);
                if ($snap !== null) {
                    $payload = $snap->getPayload();
                    $frozen = true;
                }
            }
            $payload ??= $this->results->compute($p, null);
            $ps = $p->getStartDateCollection()->format('Y-m-d');
            $pe = $p->getEndDateCollection()->format('Y-m-d');
            $len = fn (string $a, string $b) => (int) (new \DateTimeImmutable($a))->diff(new \DateTimeImmutable($b))->format('%a') + 1;
            $out[] = [
                'id' => (int) $p->getId(), 'name' => $p->getNomCollection(), 'start' => $p->getStartDateCollection()->format('Y-m-d'), 'end' => $p->getEndDateCollection()->format('Y-m-d'),
                'status' => $this->calendar->status($p), 'started' => $p->getStartDateCollection()->format('Y-m-d') <= $today,
                'revenue' => (int) ($payload['sales']['revenue'] ?? 0), 'grossMargin' => (int) ($payload['result']['gross'] ?? 0),
                'net' => (int) ($payload['result']['net'] ?? 0), 'netAfterFixed' => isset($payload['result']['netAfterFixed']) ? (int) $payload['result']['netAfterFixed'] : null,
                'otherRevenue' => (int) ($payload['otherRevenue']['total'] ?? 0), 'frozen' => $frozen,
                // exercice non aligné sur les périodes (EX2) : une période à cheval garde son résultat P2 entier
                'partial' => $ps < $jan || $pe > $dec, 'overlapDays' => $len(max($ps, $jan), min($pe, $dec)), 'days' => $len($ps, $pe),
            ];
        }

        return $out;
    }

    /** Leviers chiffrés de l'année en cours, triés par effet décroissant */
    private function levers(\DateTimeImmutable $today, int $pct, int $remainingSales, ?array $projection, ?array $gap, array $periods, array $months, string $locale): array
    {
        $tr = fn (string $key, string $part, array $p = []) => $this->translator->trans("year.levers.$key.$part", $p, 'gestion', $locale);
        $money = fn (int $v) => $this->advices->format($v, 'money', $locale);
        $levers = [];
        $required = $gap !== null && $remainingSales > 0 ? round($gap['margin'] / $remainingSales * 100, 1) : null;
        $levers[] = ['key' => 'price', 'label' => $tr('price', 'label', ['%percent%' => $pct]), 'effect' => (int) round($remainingSales * $pct / 100),
            'detail' => $tr('price', 'detail', ['%percent%' => $pct, '%sales%' => $money($remainingSales)]), 'percent' => $pct, 'requiredPercent' => $required];
        // Stock dormant : effets des conseils R2 (SLOW_STOCK) et R9 (END_CLEARANCE) actifs de la période en cours, sans recalcul
        $period = $this->calendar->findByDate($today);
        $rows = $period === null ? [] : $this->conn()->fetchAllAssociative("SELECT effect_cents, params FROM gestion_advice WHERE period_id = ? AND active AND status = 'new' AND kind IN ('SLOW_STOCK', 'END_CLEARANCE')", [$period->getId()]);
        $ids = [];
        foreach ($rows as $r) {
            foreach ((json_decode((string) $r['params'], true) ?: [])['productIds'] ?? [] as $pid) {
                $ids[] = (int) $pid;
            }
        }
        $dormant = array_sum(array_map(fn ($r) => max(0, (int) $r['effect_cents']), $rows));
        $levers[] = ['key' => 'dormantStock', 'label' => $tr('dormantStock', 'label'), 'effect' => $dormant, 'detail' => $tr('dormantStock', 'detail', ['%count%' => count($rows)]),
            'advices' => count($rows), 'productIds' => array_values(array_unique($ids))];
        // Poids des frais fixes : frais fixes ÷ marge, effet d'une baisse de 10 % des frais fixes restants, deux catégories les plus lourdes
        $remainingFixed = $projection['remaining']['fixedCosts'] ?? 0;
        $byCat = $projection === null ? [] : $this->fixedCosts->between($projection['remaining']['from'], $projection['remaining']['to'], $locale)['byCategory'];
        usort($byCat, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        $weight = ($projection['grossMargin'] ?? 0) > 0 ? round($projection['fixedCosts'] / $projection['grossMargin'] * 100, 1) : null;
        $levers[] = ['key' => 'fixedCostsWeight', 'label' => $tr('fixedCostsWeight', 'label'), 'effect' => (int) round($remainingFixed * self::FIXED_CUT),
            'detail' => $tr('fixedCostsWeight', 'detail', ['%weight%' => $weight === null ? '—' : $this->advices->format($weight, 'percent', $locale)]), 'weight' => $weight, 'topCategories' => array_slice($byCat, 0, 2)];
        // Période (ou mois) la plus déficitaire : pour montrer d'où vient l'écart
        $started = array_values(array_filter($periods, fn ($p) => $p['started']));
        if ($started !== []) {
            usort($started, fn ($a, $b) => (($a['netAfterFixed'] ?? $a['net']) + $a['otherRevenue']) <=> (($b['netAfterFixed'] ?? $b['net']) + $b['otherRevenue']));
            $w = $started[0];
            $wr = ($w['netAfterFixed'] ?? $w['net']) + $w['otherRevenue'];
            $levers[] = ['key' => 'worstPeriod', 'label' => $tr('worstPeriod', 'label'), 'effect' => max(0, -$wr), 'detail' => $tr('worstPeriod', 'detail', ['%name%' => $w['name'], '%result%' => $money($wr)]),
                'periodId' => $w['id'], 'month' => null];
        } else {
            $elapsed = array_values(array_filter($months, fn ($m) => $m['elapsed'] !== false));
            usort($elapsed, fn ($a, $b) => $a['result'] <=> $b['result']);
            if ($elapsed !== []) {
                $w = $elapsed[0];
                $levers[] = ['key' => 'worstPeriod', 'label' => $tr('worstPeriod', 'label'), 'effect' => max(0, -$w['result']),
                    'detail' => $tr('worstPeriod', 'detail', ['%name%' => $this->monthName($w['month'], $locale), '%result%' => $money($w['result'])]), 'periodId' => null, 'month' => $w['month']];
            }
        }
        foreach ($levers as &$l) {
            $l['coversGap'] = $gap !== null && $l['effect'] >= $gap['margin'];
        }
        unset($l);
        usort($levers, fn ($a, $b) => $b['effect'] <=> $a['effect']);

        return $levers;
    }

    /** Année terminée : bilan, meilleurs et pires mois et périodes, « ce qui aurait suffi » */
    private function finishedBlock(array $totals, array $months, array $periods, ?array $breakEven): array
    {
        $byResult = $months;
        usort($byResult, fn ($a, $b) => $b['result'] <=> $a['result']);
        $short = fn ($m) => ['month' => $m['month'], 'result' => $m['result'], 'sales' => $m['sales']];
        $pr = fn ($p) => ($p['netAfterFixed'] ?? $p['net']) + $p['otherRevenue'];
        $ps = $periods;
        usort($ps, fn ($a, $b) => $pr($b) <=> $pr($a));
        $loss = max(0, -$totals['result']);

        return [
            'bilan' => self::publicBlock($totals) + ['profitable' => $totals['result'] >= 0],
            'bestMonths' => array_map($short, array_slice($byResult, 0, 3)),
            'worstMonths' => array_map($short, array_slice(array_reverse($byResult), 0, 3)),
            'bestPeriod' => $ps === [] ? null : ['id' => $ps[0]['id'], 'name' => $ps[0]['name'], 'result' => $pr($ps[0])],
            'worstPeriod' => $ps === [] ? null : ['id' => end($ps)['id'], 'name' => end($ps)['name'], 'result' => $pr(end($ps))],
            'hindsight' => [
                'priceRequiredPercent' => $loss === 0 ? 0.0 : ($totals['sales'] > 0 ? round($loss / $totals['sales'] * 100, 1) : null),
                'fixedCostsCutRequired' => min($loss, $totals['fixedCosts']),
                'salesRequired' => $breakEven['sales'] ?? null,
            ],
        ];
    }

    private function fallbackComment(array $o, string $locale): array
    {
        $money = fn (?int $v) => $v === null ? '—' : $this->advices->format($v, 'money', $locale);
        $f = $o['finished'];
        $profitable = $f['bilan']['profitable'];
        $p = ['%result%' => $money($o['totals']['result']), '%year%' => $o['fiscalYear']['label'], '%sales%' => $money($f['hindsight']['salesRequired']),
            '%price%' => $f['hindsight']['priceRequiredPercent'] === null ? '—' : $this->advices->format($f['hindsight']['priceRequiredPercent'], 'percent', $locale),
            '%fixed%' => $money($o['totals']['fixedCosts']), '%best%' => $f['bestMonths'] === [] ? '—' : $this->monthName($f['bestMonths'][0]['month'], $locale),
            '%worst%' => $f['worstMonths'] === [] ? '—' : $this->monthName($f['worstMonths'][0]['month'], $locale)];
        $k = $profitable ? 'profitable' : 'loss';

        return ['title' => $this->translator->trans("year.comment.{$k}_title", $p, 'gestion', $locale), 'text' => $this->translator->trans("year.comment.{$k}_text", $p, 'gestion', $locale),
            'lessons' => [$this->translator->trans('year.comment.lesson_months', $p, 'gestion', $locale), $this->translator->trans("year.comment.lesson_$k", $p, 'gestion', $locale)],
            'textSource' => 'rules'];
    }

    private function system(string $locale): string
    {
        $language = $locale === 'en' ? 'anglais' : 'français';

        return "Tu es le guide de gestion d'un commerçant indépendant. On te donne le bilan de son année terminée, tout calculé par le logiciel.\n"
            . "Ta tâche : un titre court (60 caractères au plus), une explication de deux ou trois phrases (400 caractères) et 2 ou 3 leçons pour l'année suivante (`lessons`, une phrase chacune), en $language, au vouvoiement. `recommended` vaut toujours \"year\".\n"
            . "Règles strictes :\n"
            . "- N'écris AUCUN nombre qui ne figure pas tel quel dans les données. Recopie les valeurs telles qu'elles sont écrites ; n'additionne, ne soustrais, ne multiplie rien.\n"
            . "- Les « ce qui aurait suffi » (hindsight) sont des estimations à quantités égales ; dis-le.\n"
            . "- Pas de jugement sur le commerçant : des constats et des pistes. Rien ne s'applique tout seul.";
    }

    /** Période existante de l'année (la dernière qui la recoupe) pour ranger l'avis en cache, sans en créer ; null s'il n'y en a pas */
    private function cachePeriod(string $jan, string $dec): ?Collections
    {
        $id = $this->conn()->fetchOne("SELECT id FROM collections WHERE kind = 'period' AND del = false AND start_date_collection <= ? AND end_date_collection >= ? ORDER BY start_date_collection DESC, id DESC LIMIT 1", [$dec . ' 23:59:59', $jan]);

        return $id === false ? null : $this->emProvider->getEntityManager()->find(Collections::class, (int) $id);
    }

    private function monthName(int $month, string $locale): string
    {
        $fr = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $en = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

        return ($locale === 'en' ? $en : $fr)[$month - 1];
    }

    private function year(mixed $value, int $current): int
    {
        if ($value === null || $value === '') {
            return $current;
        }
        if (!is_numeric($value) || (int) $value != $value || (int) $value < self::MIN_YEAR || (int) $value > $current) {
            throw new GestionValidationException(['year' => 'INVALID_YEAR']);
        }

        return (int) $value;
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
