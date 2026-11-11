<?php

namespace App\Gestion\Services;

use App\Entity\Collections;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Statistiques unifiées de la gestion (lot S1, 11/11/2026, `docs/statistiques-gestion.md`, contrat validé par l'application) : une route,
 * **une seule règle**, celle du résultat de période P2 (`PeriodResultCalculator::dailySales` : articles hors taxes, retours retirés, incomplètes
 * et annulées exclues, marge sur les ventes au coût connu, locations et abonnements à part), au fuseau du magasin. Chaque indicateur porte sa
 * valeur, la même mesure sur la plage précédente et sur la même plage l'an dernier, les écarts et une courte série ; une série complète pour le
 * graphique, avec l'an dernier aligné. Trois requêtes groupées par jour (plage, précédente, an dernier), plus la valeur du stock. Lecture seule.
 */
class GestionStatisticsService
{
    public const RANGES = ['today', '7d', 'month', 'year'];
    public const SOURCES = ['all' => null, 'ecommerce' => 1, 'pos' => 2, 'mobile_app' => 3];
    public const MAX_DAYS = 366;
    public const DAY_MAX = 62;    // granularité jour jusqu'à 62 jours
    public const WEEK_MAX = 190;  // semaine jusqu'à 190 jours, mois au-delà
    private const MONTHS_FR = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    private const MONTHS_EN = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodResultCalculator $results,
        private readonly PeriodCalendar $calendar,
        private readonly GestionStockSnapshotService $stock,
        private readonly TranslatorInterface $translator,
        private readonly GestionAdviceService $advices,
        private readonly ?TenantCurrencyProvider $currency = null,
        private readonly ?GestionFiscalYearService $fiscal = null, // lot AN partie EX : range=year suit l'exercice du site
    ) {
    }

    /** GET /api/gestion/statistiques?from=&to=|periodId=|range=&source=&locale= */
    public function overview(array $query, string $locale, ?\DateTimeImmutable $today = null): array
    {
        $today ??= $this->calendar->today();
        $todayKey = $today->format('Y-m-d');
        $sourceKey = is_string($query['source'] ?? null) && $query['source'] !== '' ? strtolower($query['source']) : 'all';
        if (!array_key_exists($sourceKey, self::SOURCES)) {
            throw new GestionValidationException(['source' => 'INVALID_SOURCE']);
        }
        $source = self::SOURCES[$sourceKey];
        [$type, $cur, $prev] = $this->ranges($query, $today);
        $ly = [self::shiftYears($cur[0], -1), self::shiftYears($cur[1], -1)];
        $days = self::days($cur[0], $cur[1]);
        $granularity = $days <= self::DAY_MAX ? 'day' : ($days <= self::WEEK_MAX ? 'week' : 'month');

        $daily = $this->results->dailySales($cur[0], $cur[1], $source);
        $dailyPrev = $prev === null ? [] : $this->results->dailySales($prev[0], $prev[1], $source);
        $dailyLy = $this->results->dailySales($ly[0], $ly[1], $source);
        $m = self::metrics($daily);
        $mp = $prev === null ? null : self::metrics($dailyPrev);
        $ml = self::metrics($dailyLy);
        $hasLy = $ml['orders'] > 0 || $ml['salesGross'] > 0 || $ml['rentals'] != 0 || $ml['subscriptions'] != 0;

        // Points du graphique (jusqu'à aujourd'hui) et l'an dernier aligné (mêmes dates décalées d'un an)
        $points = [];
        $series = [];
        foreach (self::buckets($cur[0], min($cur[1], $todayKey), $granularity) as [$key, $from, $to]) {
            $pm = self::metrics(self::slice($daily, $from, $to));
            $pl = self::metrics(self::slice($dailyLy, self::shiftYears($from, -1), self::shiftYears($to, -1)));
            $points[] = ['key' => $key, 'from' => $from, 'to' => $to, 'revenue' => $pm['revenue'], 'grossMargin' => $pm['grossMargin'], 'orders' => $pm['orders'],
                'lastYear' => ['revenue' => $pl['revenue'], 'grossMargin' => $pl['grossMargin'], 'orders' => $pl['orders']]];
            $series[] = $pm;
        }
        $spark = fn (string $k) => array_map(fn ($pm) => $pm[$k], $series);

        $stockNow = $this->stock->current($today);
        $stockPrev = $prev === null ? null : $this->stock->at($prev[1]);
        $stockLy = $this->stock->at($ly[1]);
        $indicator = fn (string $k, string $unit) => self::indicator($m[$k], $mp[$k] ?? null, $hasLy ? $ml[$k] : null, $unit, $spark($k), $prev !== null);
        $indicators = [
            'revenue' => $indicator('revenue', 'money'),
            'grossMargin' => $indicator('grossMargin', 'money'),
            'grossMarginRate' => $indicator('grossMarginRate', 'percent'),
            'orders' => $indicator('orders', 'count'),
            'averageBasket' => $indicator('averageBasket', 'money'),
            'returnRate' => $indicator('returnRate', 'percent') + ['returns' => ['amount' => $m['returns'], 'orders' => $m['returnOrders']]],
            'stockValue' => self::indicator($stockNow['saleValue'], $stockPrev['saleValue'] ?? null, $stockLy['saleValue'] ?? null, 'money', [], $prev !== null)
                + ['costValue' => $stockNow['costValue'], 'snapshots' => ['previous' => $stockPrev, 'lastYear' => $stockLy]],
        ];
        $other = fn (string $k) => self::indicator($m[$k], $mp[$k] ?? null, $hasLy ? $ml[$k] : null, 'money', null, $prev !== null);

        $caveats = [];
        if ($m['unknown'] != 0) {
            $caveats[] = $this->caveat('UNKNOWN_COST', $locale, ['%amount%' => $m['unknown']]) + ['amount' => $m['unknown']];
        }
        if ($stockPrev === null && $stockLy === null) {
            $caveats[] = $this->caveat('STOCK_VALUE_NOW_ONLY', $locale);
        }
        if ($cur[0] <= $todayKey && $todayKey <= $cur[1]) {
            $caveats[] = $this->caveat('PARTIAL_DAY', $locale);
        }
        if (!$hasLy) {
            $caveats[] = $this->caveat('NO_LAST_YEAR_DATA', $locale);
        }

        return [
            'currency' => $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT,
            'source' => $sourceKey,
            'range' => ['type' => $type, 'from' => $cur[0], 'to' => $cur[1], 'days' => $days, 'granularity' => $granularity],
            'previous' => $prev === null ? null : ['from' => $prev[0], 'to' => $prev[1]],
            'lastYear' => ['from' => $ly[0], 'to' => $ly[1]],
            'comparison' => [
                'currentLabel' => self::label($cur[0], $cur[1], $locale, $todayKey),
                'previousLabel' => $prev === null ? null : self::label($prev[0], $prev[1], $locale, $todayKey),
                'lastYearLabel' => self::label($ly[0], $ly[1], $locale, $todayKey),
            ],
            'indicators' => $indicators,
            'otherRevenue' => ['rentals' => $other('rentals'), 'subscriptions' => $other('subscriptions')],
            'chart' => ['granularity' => $granularity, 'points' => $points],
            'caveats' => $caveats,
        ];
    }

    /**
     * Plage demandée, plage précédente (null pour `year`) : jours du magasin Y-m-d inclus.
     *
     * @return array{0: string, 1: array{0: string, 1: string}, 2: array{0: string, 1: string}|null}
     */
    public function ranges(array $query, \DateTimeImmutable $today): array
    {
        $t = $today->format('Y-m-d');
        $day = fn (string $d, int $n) => (new \DateTimeImmutable($d))->modify("$n days")->format('Y-m-d');
        $from = $query['from'] ?? null;
        $to = $query['to'] ?? null;
        $periodId = $query['periodId'] ?? null;
        if (($from !== null && $from !== '') || ($to !== null && $to !== '')) {
            $errors = [];
            foreach (['from' => $from, 'to' => $to] as $k => $v) {
                if (!is_string($v) || !self::isDate($v)) {
                    $errors[$k] = $v === null || $v === '' ? 'REQUIRED' : 'INVALID_DATE';
                }
            }
            if ($errors === [] && $to < $from) {
                $errors['to'] = 'BEFORE_START';
            }
            if ($errors === [] && self::days($from, $to) > self::MAX_DAYS) {
                $errors['to'] = 'RANGE_TOO_LONG';
            }
            if ($errors !== []) {
                throw new GestionValidationException($errors);
            }
            $n = self::days($from, $to);

            return ['custom', [$from, $to], [$day($from, -$n), $day($from, -1)]];
        }
        if ($periodId !== null && $periodId !== '') {
            $period = is_numeric($periodId) ? $this->emProvider->getEntityManager()->find(Collections::class, (int) $periodId) : null;
            if ($period === null) {
                throw new GestionConflictException('PERIOD_NOT_FOUND', 'Période introuvable.', 404, ['periodId' => $periodId]);
            }
            $f = $period->getStartDateCollection()->format('Y-m-d');
            $e = $period->getEndDateCollection()->format('Y-m-d');
            if (self::days($f, $e) > self::MAX_DAYS) {
                throw new GestionValidationException(['periodId' => 'RANGE_TOO_LONG']);
            }
            $n = self::days($f, $e);

            return ['period', [$f, $e], [$day($f, -$n), $day($f, -1)]];
        }
        $range = is_string($query['range'] ?? null) && $query['range'] !== '' ? strtolower($query['range']) : 'month';
        switch ($range) {
            case 'today':
                return ['today', [$t, $t], [$day($t, -1), $day($t, -1)]];
            case '7d':
                return ['7d', [$day($t, -6), $t], [$day($t, -13), $day($t, -7)]];
            case 'month':
                $first = $today->modify('first day of this month')->format('Y-m-d');
                $prevFirst = $today->modify('first day of previous month');
                $prevEnd = $prevFirst->setDate((int) $prevFirst->format('Y'), (int) $prevFirst->format('n'), min((int) $today->format('j'), (int) $prevFirst->format('t')));

                return ['month', [$first, $t], [$prevFirst->format('Y-m-d'), $prevEnd->format('Y-m-d')]];
            case 'year': // exercice en cours (lot AN partie EX) : du 1er du mois de début à aujourd'hui ; année civile quand il commence en janvier
                $sm = $this->fiscal?->startMonth() ?? 1;

                return ['year', [GestionFiscalYearService::bounds(GestionFiscalYearService::startYearOf($today, $sm), $sm)[0], $t], null];
        }

        throw new GestionValidationException(['range' => 'INVALID_RANGE']);
    }

    /** Totaux d'une liste de jours (agrégats de `dailySales`) et indicateurs dérivés */
    private static function metrics(array $days): array
    {
        $c = static fn (mixed $v): float => (float) $v;
        $sum = static fn (string $k) => array_sum(array_map(fn ($r) => $c($r[$k] ?? 0), $days));
        $sales = (int) round($sum('sales_amount'));
        $returns = (int) round($sum('returns_amount'));
        $unknown = (int) round($sum('unknown_revenue'));
        $cogs = (int) round($sum('cogs'));
        $orders = (int) $sum('orders_sold');
        $revenue = $sales - $returns;
        $effective = $revenue - $unknown;
        $gross = $effective - $cogs;

        return [
            'salesGross' => $sales, 'returns' => $returns, 'returnOrders' => (int) $sum('orders_returned'), 'revenue' => $revenue, 'unknown' => $unknown,
            'grossMargin' => $gross, 'grossMarginRate' => $effective > 0 ? round($gross / $effective * 100, 2) : null, 'orders' => $orders,
            'averageBasket' => $orders > 0 ? (int) round($sales / $orders) : null, // ventes brutes hors taxes ÷ ventes, retours exclus (Q1)
            'returnRate' => $sales > 0 ? round($returns / $sales * 100, 2) : null,  // montant des retours ÷ ventes brutes (Q2)
            'rentals' => (int) round($sum('rentals')), 'subscriptions' => (int) round($sum('subscriptions')),
        ];
    }

    /** Indicateur : valeur, comparaisons, écarts (en % ; en points pour un pourcentage) et courte série */
    private static function indicator(int|float|null $value, int|float|null $previous, int|float|null $lastYear, string $unit, ?array $spark, bool $hasPrevious): array
    {
        $change = static function ($v, $c) use ($unit): ?float {
            if ($v === null || $c === null) {
                return null;
            }
            if ($unit === 'percent') {
                return round($v - $c, 2);
            }

            return $c == 0 ? null : round(($v - $c) / abs($c) * 100, 1);
        };
        $out = ['value' => $value, 'unit' => $unit, 'previous' => $hasPrevious ? $previous : null, 'lastYear' => $lastYear,
            'changeVsPrevious' => $hasPrevious ? $change($value, $previous) : null, 'changeVsLastYear' => $change($value, $lastYear),
            'changeUnit' => $unit === 'percent' ? 'points' : 'percent'];

        return $spark === null ? $out : $out + ['spark' => $spark];
    }

    /** @return list<array{0: string, 1: string, 2: string}> [clé, du, au] par jour, semaine (lundi à dimanche) ou mois, bornés à [from, to] */
    private static function buckets(string $from, string $to, string $granularity): array
    {
        $out = [];
        for ($d = new \DateTimeImmutable($from); ($k = $d->format('Y-m-d')) <= $to;) {
            if ($granularity === 'day') {
                $end = $d;
                $key = $k;
            } elseif ($granularity === 'week') {
                $end = $d->modify('sunday this week');
                $key = $d->modify('monday this week')->format('o-\WW');
            } else {
                $end = $d->modify('last day of this month');
                $key = $d->format('Y-m');
            }
            $e = min($end->format('Y-m-d'), $to);
            $out[] = [$key, $k, $e];
            $d = (new \DateTimeImmutable($e))->modify('+1 day');
        }

        return $out;
    }

    private static function slice(array $daily, string $from, string $to): array
    {
        return array_filter($daily, fn ($day) => $day >= $from && $day <= $to, ARRAY_FILTER_USE_KEY);
    }

    /** Même date décalée de `$years` ans ; un 29 février devient le 28 */
    public static function shiftYears(string $day, int $years): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $day));
        $y += $years;
        $max = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');

        return sprintf('%04d-%02d-%02d', $y, $m, min($d, $max));
    }

    /** Libellé court d'une plage : « 1-11 oct. », « 28 sept. - 4 oct. », l'année quand ce n'est pas l'année en cours ; en anglais « Oct 1-11 » */
    public static function label(string $from, string $to, string $locale, string $today): string
    {
        $f = new \DateTimeImmutable($from);
        $t = new \DateTimeImmutable($to);
        $en = $locale === 'en';
        $mon = fn (\DateTimeImmutable $d) => ($en ? self::MONTHS_EN : self::MONTHS_FR)[(int) $d->format('n') - 1];
        $year = fn (\DateTimeImmutable $d) => $d->format('Y') === substr($today, 0, 4) ? '' : ($en ? ', ' : ' ') . $d->format('Y');
        if ($from === $to) {
            return $en ? $mon($f) . ' ' . $f->format('j') . $year($f) : $f->format('j') . ' ' . $mon($f) . $year($f);
        }
        if ($f->format('Y-m') === $t->format('Y-m')) {
            return $en ? $mon($f) . ' ' . $f->format('j') . '-' . $t->format('j') . $year($t) : $f->format('j') . '-' . $t->format('j') . ' ' . $mon($t) . $year($t);
        }
        if ($f->format('Y') === $t->format('Y')) {
            return $en ? $mon($f) . ' ' . $f->format('j') . ' - ' . $mon($t) . ' ' . $t->format('j') . $year($t) : $f->format('j') . ' ' . $mon($f) . ' - ' . $t->format('j') . ' ' . $mon($t) . $year($t);
        }
        $full = fn (\DateTimeImmutable $d) => $en ? $mon($d) . ' ' . $d->format('j') . ', ' . $d->format('Y') : $d->format('j') . ' ' . $mon($d) . ' ' . $d->format('Y');

        return $full($f) . ' - ' . $full($t);
    }

    private static function days(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%a') + 1;
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v;
    }

    private function caveat(string $code, string $locale, array $params = []): array
    {
        if (isset($params['%amount%'])) {
            $params['%amount%'] = $this->advices->format($params['%amount%'], 'money', $locale);
        }

        return ['code' => $code, 'text' => $this->translator->trans("statistics.caveats.$code", $params, 'gestion', $locale)];
    }
}
