<?php

namespace App\Services\GestionService;

use App\Entity\Collections;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Statistiques des locations de la gestion (lot LOC1, 11/11/2026, `docs/locations-gestion.md`, contrat LOC validé par l'application).
 *
 * - Revenu à la **date de vente** : exactement `otherRevenue.rentals` du résultat P2 (lignes avec réservation, hors taxes, commandes incomplètes
 *   et annulées exclues ; une réservation annulée dont la commande n'est ni annulée ni remboursée reste de l'argent gardé, Q4). En plus,
 *   `revenueByRentalDate` : le montant de chaque ligne réparti au prorata du temps loué dans la plage.
 * - Occupation = temps loué ÷ temps offert, réservations annulées exclues, au fuseau du magasin. Granularité `days` : en jours (capacité × jours) ;
 *   sinon en heures (capacité × heures d'ouverture par jour, 24 h sans heures d'ouverture, remarque NO_OPENING_HOURS).
 * Lecture seule.
 */
class GestionRentalStatisticsService
{
    public const MAX_DAYS = 366;
    public const DEFAULT_DAYS = 30;
    /** Seuils des jours pleins et creux, partagés avec les règles RENT1 et RENT2 du guide (LOC2) */
    public const PEAK_OCCUPANCY = 80.0;
    public const LOW_WEEKDAY_OCCUPANCY = 15.0;
    public const CANCELLED = 'CANCELLED';
    private const WEEKDAYS_FR = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    private const WEEKDAYS_EN = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodResultCalculator $results,
        private readonly PeriodCalendar $calendar,
        private readonly StoreClock $clock,
        private readonly TranslatorInterface $translator,
        private readonly ?TenantCurrencyProvider $currency = null,
    ) {
    }

    /** GET /api/gestion/locations/statistiques?periodId=|from=&to=&productIds=&locale= */
    public function statistics(array $query, string $locale, ?\DateTimeImmutable $today = null): array
    {
        $today ??= $this->calendar->today();
        [$type, $from, $to] = $this->range($query, $today);
        $only = self::ids($query['productIds'] ?? null);
        $s = $this->compute($from, $to, $only);
        $label = fn (int $d) => ($locale === 'en' ? self::WEEKDAYS_EN : self::WEEKDAYS_FR)[$d];
        $caveats = ['REVENUE_AT_SALE_DATE', 'CANCELLED_EXCLUDED_FROM_OCCUPANCY'];
        $noHours = array_values(array_map(fn ($p) => $p['productId'], array_filter($s['products'], fn ($p) => $p['unit'] === 'hours' && !$p['openingHours'])));
        if ($noHours !== []) {
            $caveats[] = 'NO_OPENING_HOURS';
        }
        if (array_filter($s['products'], fn ($p) => $p['capped']) !== []) {
            $caveats[] = 'OCCUPANCY_CAPPED';
        }

        return [
            'currency' => $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT,
            'range' => ['type' => $type, 'from' => $from, 'to' => $to, 'days' => self::days($from, $to)],
            'revenue' => $s['revenue'], 'revenueByRentalDate' => $s['revenueByRentalDate'], 'rentals' => $s['rentals'], 'cancellations' => $s['cancellations'],
            'products' => array_map(fn ($p) => array_diff_key($p, ['capped' => 1, 'byWeekday' => 1, 'rentalsByWeekday' => 1]), $s['products']),
            'weekdays' => array_map(fn ($w) => $w + ['label' => $label($w['weekday'])], $s['weekdays']),
            'highDays' => array_values(array_map(fn ($w) => $w['weekday'], array_filter($s['weekdays'], fn ($w) => $w['occupancy'] !== null && $w['occupancy'] >= self::PEAK_OCCUPANCY))),
            'lowDays' => array_values(array_map(fn ($w) => $w['weekday'], array_filter($s['weekdays'], fn ($w) => $w['occupancy'] !== null && $w['occupancy'] < self::LOW_WEEKDAY_OCCUPANCY))),
            'thresholds' => ['peak' => self::PEAK_OCCUPANCY, 'low' => self::LOW_WEEKDAY_OCCUPANCY],
            'caveats' => array_map(fn ($c) => ['code' => $c, 'text' => $this->translator->trans("rental.caveats.$c", [], 'gestion', $locale)] + ($c === 'NO_OPENING_HOURS' ? ['productIds' => $noHours] : []), $caveats),
        ];
    }

    /**
     * Calcul d'une plage de jours du magasin [from, to] (aussi pour les règles du guide, LOC2).
     *
     * @param list<int>|null $only articles retenus (null = tous les articles louables)
     * @return array{revenue: int, revenueByRentalDate: int, rentals: int, cancellations: int, products: list<array<string, mixed>>, weekdays: list<array<string, mixed>>}
     */
    public function compute(string $from, string $to, ?array $only = null): array
    {
        $tz = $this->clock->timezone();
        [$fromUtc, $toUtc] = $this->clock->range($from, $to);
        $rangeStart = new \DateTimeImmutable($from . ' 00:00:00', $tz);
        $rangeEnd = (new \DateTimeImmutable($to . ' 00:00:00', $tz))->modify('+1 day');
        $conn = $this->conn();
        $products = [];
        foreach ($conn->fetchAllAssociative('SELECT bc.product_id, p.name, bc.granularity, bc.stock_quantity, bc.opening_start, bc.opening_end FROM booking_configuration bc JOIN product p ON p.id = bc.product_id ORDER BY p.name, bc.product_id') as $r) {
            $pid = (int) $r['product_id'];
            if ($only !== null && !in_array($pid, $only, true)) {
                continue;
            }
            $unit = $r['granularity'] === 'days' ? 'days' : 'hours';
            $hours = self::openingHours($r['opening_start'], $r['opening_end']);
            $products[$pid] = ['productId' => $pid, 'name' => (string) $r['name'], 'granularity' => (string) $r['granularity'], 'unit' => $unit,
                'capacity' => max(0, (int) $r['stock_quantity']), 'openingHours' => $hours !== null, 'hoursPerDay' => $unit === 'days' ? null : ($hours ?? 24.0),
                'booked' => 0.0, 'offered' => 0.0, 'rentals' => 0, 'cancellations' => 0, 'durations' => [], 'revenue' => 0, 'revenueByRentalDate' => 0.0,
                'byWeekday' => array_fill(1, 7, ['booked' => 0.0, 'offered' => 0.0]), 'rentalsByWeekday' => array_fill(1, 7, 0)];
        }
        // Temps offert, jour par jour
        for ($d = $rangeStart; $d < $rangeEnd; $d = $d->modify('+1 day')) {
            $w = (int) $d->format('N');
            foreach ($products as $pid => $p) {
                $offer = $p['unit'] === 'days' ? $p['capacity'] : $p['capacity'] * $p['hoursPerDay'];
                $products[$pid]['offered'] += $offer;
                $products[$pid]['byWeekday'][$w]['offered'] += $offer;
            }
        }
        // Réservations qui chevauchent la plage : temps loué (hors annulées), locations commencées dans la plage, annulations, durées
        $rentalsByWeekday = array_fill(1, 7, 0);
        $bookings = $conn->fetchAllAssociative(
            'SELECT b.id, b.product_id, b.start_at, b.end_at, b.quantity, b.status, b.order_item_id FROM booking b WHERE b.start_at < :to AND b.end_at > :from AND b.product_id IS NOT NULL',
            ['from' => $fromUtc->format('Y-m-d H:i:s'), 'to' => $toUtc->format('Y-m-d H:i:s')]
        );
        $shares = []; // order_item_id => part du temps loué dans la plage (revenu par date de location)
        foreach ($bookings as $b) {
            $pid = (int) $b['product_id'];
            if (!isset($products[$pid])) {
                continue;
            }
            $start = (new \DateTimeImmutable((string) $b['start_at'], new \DateTimeZone('UTC')))->setTimezone($tz);
            $end = (new \DateTimeImmutable((string) $b['end_at'], new \DateTimeZone('UTC')))->setTimezone($tz);
            $qty = max(1, (int) $b['quantity']);
            $startsInRange = $start >= $rangeStart && $start < $rangeEnd;
            $total = max(1, $end->getTimestamp() - $start->getTimestamp());
            if ($b['order_item_id'] !== null) {
                $shares[(int) $b['order_item_id']] = [$pid, (min($end, $rangeEnd)->getTimestamp() - max($start, $rangeStart)->getTimestamp()) / $total];
            }
            if ($b['status'] === self::CANCELLED) {
                if ($startsInRange) {
                    ++$products[$pid]['cancellations'];
                }

                continue;
            }
            $hours = $total / 3600;
            if ($startsInRange) {
                ++$products[$pid]['rentals'];
                ++$rentalsByWeekday[(int) $start->format('N')];
                ++$products[$pid]['rentalsByWeekday'][(int) $start->format('N')];
                $products[$pid]['durations'][] = $products[$pid]['unit'] === 'days' ? max(1, (int) round($hours / 24)) : $hours;
            }
            if ($products[$pid]['unit'] === 'days') {
                // une location de N jours occupe ses N premiers jours (10 h → 10 h le lendemain = 1 jour)
                $n = max(1, (int) round($hours / 24));
                $day = $start->setTime(0, 0);
                for ($i = 0; $i < $n; $i++, $day = $day->modify('+1 day')) {
                    if ($day >= $rangeStart && $day < $rangeEnd) {
                        $products[$pid]['booked'] += $qty;
                        $products[$pid]['byWeekday'][(int) $day->format('N')]['booked'] += $qty;
                    }
                }
            } else {
                for ($day = max($start, $rangeStart)->setTime(0, 0); $day < min($end, $rangeEnd); $day = $day->modify('+1 day')) {
                    $overlap = (min($end, $day->modify('+1 day'))->getTimestamp() - max($start, $day)->getTimestamp()) / 3600;
                    if ($overlap > 0) {
                        $products[$pid]['booked'] += $qty * $overlap;
                        $products[$pid]['byWeekday'][(int) $day->format('N')]['booked'] += $qty * $overlap;
                    }
                }
            }
        }
        // Revenus : à la date de vente (règle de P2), et au prorata du temps loué dans la plage
        $f = PeriodResultCalculator::NET_FACTOR;
        $signed = "(CASE WHEN o.order_type_id = 2 THEN -ABS(oi.total_price) ELSE oi.total_price END) * ($f)";
        foreach ($conn->fetchAllAssociative("SELECT b.product_id AS pid, SUM($signed) AS v FROM order_items oi JOIN \"order\" o ON o.id = oi.order_associated_id JOIN booking b ON b.order_item_id = oi.id"
            . ' WHERE o.order_date >= :from AND o.order_date < :to AND o.order_type_id IN (1, 2) AND o.status_id NOT IN (1, 7) GROUP BY b.product_id', ['from' => $fromUtc->format('Y-m-d H:i:s'), 'to' => $toUtc->format('Y-m-d H:i:s')]) as $r) {
            if (isset($products[(int) $r['pid']])) {
                $products[(int) $r['pid']]['revenue'] = (int) round((float) $r['v']);
            }
        }
        if ($shares !== []) {
            foreach ($conn->fetchAllAssociative("SELECT oi.id, $signed AS v FROM order_items oi JOIN \"order\" o ON o.id = oi.order_associated_id WHERE oi.id IN (?) AND o.order_type_id IN (1, 2) AND o.status_id NOT IN (1, 7)",
                [array_keys($shares)], [\Doctrine\DBAL\ArrayParameterType::INTEGER]) as $r) {
                [$pid, $share] = $shares[(int) $r['id']];
                $products[$pid]['revenueByRentalDate'] += (float) $r['v'] * max(0, $share);
            }
        }

        $outProducts = [];
        foreach ($products as $p) {
            $raw = $p['offered'] > 0 ? $p['booked'] / $p['offered'] * 100 : null;
            $outProducts[] = [
                'productId' => $p['productId'], 'name' => $p['name'], 'granularity' => $p['granularity'], 'unit' => $p['unit'], 'capacity' => $p['capacity'],
                'openingHours' => $p['openingHours'], 'hoursPerDay' => $p['hoursPerDay'],
                'occupancy' => $raw === null ? null : round(min(100.0, $raw), 1), 'bookedTime' => round($p['booked'], 1), 'offeredTime' => round($p['offered'], 1),
                'revenue' => $p['revenue'], 'revenueByRentalDate' => (int) round($p['revenueByRentalDate']), 'rentals' => $p['rentals'],
                'averageDuration' => $p['durations'] === [] ? null : round(array_sum($p['durations']) / count($p['durations']), 1), 'cancellations' => $p['cancellations'],
                'capped' => $raw !== null && $raw > 100, 'byWeekday' => $p['byWeekday'], 'rentalsByWeekday' => $p['rentalsByWeekday'],
            ];
        }
        // Par jour de la semaine : moyenne des occupations des articles (unités différentes), locations commencées ce jour-là
        $weekdays = [];
        for ($w = 1; $w <= 7; $w++) {
            $ratios = [];
            foreach ($products as $p) {
                if ($p['byWeekday'][$w]['offered'] > 0) {
                    $ratios[] = min(1.0, $p['byWeekday'][$w]['booked'] / $p['byWeekday'][$w]['offered']);
                }
            }
            $weekdays[] = ['weekday' => $w, 'occupancy' => $ratios === [] ? null : round(array_sum($ratios) / count($ratios) * 100, 1), 'rentals' => $rentalsByWeekday[$w]];
        }
        $daily = $this->results->dailySales($from, $to);

        return [
            'revenue' => (int) round(array_sum(array_map(fn ($r) => (float) $r['rentals'], $daily))), // = otherRevenue.rentals de P2
            'revenueByRentalDate' => array_sum(array_column($outProducts, 'revenueByRentalDate')),
            'rentals' => array_sum(array_column($outProducts, 'rentals')), 'cancellations' => array_sum(array_column($outProducts, 'cancellations')),
            'products' => $outProducts, 'weekdays' => $weekdays,
        ];
    }

    /** @return array{0: string, 1: string, 2: string} type, du, au (jours du magasin) */
    private function range(array $query, \DateTimeImmutable $today): array
    {
        $from = $query['from'] ?? null;
        $to = $query['to'] ?? null;
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

            return ['custom', $from, $to];
        }
        $periodId = $query['periodId'] ?? null;
        if ($periodId !== null && $periodId !== '') {
            $period = is_numeric($periodId) ? $this->emProvider->getEntityManager()->find(Collections::class, (int) $periodId) : null;
            if ($period === null) {
                throw new GestionConflictException('PERIOD_NOT_FOUND', 'Période introuvable.', 404, ['periodId' => $periodId]);
            }

            return ['period', $period->getStartDateCollection()->format('Y-m-d'), $period->getEndDateCollection()->format('Y-m-d')];
        }
        $current = $this->calendar->findByDate($today);
        if ($current !== null) {
            return ['period', $current->getStartDateCollection()->format('Y-m-d'), $current->getEndDateCollection()->format('Y-m-d')];
        }

        return ['last30', $today->modify('-' . (self::DEFAULT_DAYS - 1) . ' days')->format('Y-m-d'), $today->format('Y-m-d')];
    }

    /** Heures d'ouverture par jour (« 09:00 » → « 18:00 » = 9), null sans heures valides */
    private static function openingHours(?string $start, ?string $end): ?float
    {
        if (!is_string($start) || !is_string($end) || !preg_match('/^(\d{1,2}):(\d{2})/', $start, $a) || !preg_match('/^(\d{1,2}):(\d{2})/', $end, $b)) {
            return null;
        }
        $h = ((int) $b[1] * 60 + (int) $b[2] - (int) $a[1] * 60 - (int) $a[2]) / 60;

        return $h > 0 ? $h : null;
    }

    /** @return list<int>|null */
    private static function ids(mixed $v): ?array
    {
        if ($v === null || $v === '') {
            return null;
        }
        $list = is_array($v) ? $v : explode(',', (string) $v);
        $ids = array_values(array_filter(array_map('intval', $list), fn ($i) => $i > 0));
        if ($ids === []) {
            throw new GestionValidationException(['productIds' => 'INVALID_ID']);
        }

        return $ids;
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

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
