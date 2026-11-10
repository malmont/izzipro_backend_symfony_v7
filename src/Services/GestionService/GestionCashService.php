<?php

namespace App\Services\GestionService;

use App\Entity\GestionCashMovement;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\CaisseService\StoreClock;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Trésorerie saisie de la gestion (lot B1b-1, 11/11/2026, `docs/tresorerie-gestion.md`) : soldes datés saisis par le gérant (le backend ne
 * connaît pas le solde bancaire), mouvements prévus récurrents, réglages, projection du solde jour par jour en deux séries :
 *  - `projection` : solde + mouvements prévus − dépenses saisies dans l'application (commandes fournisseurs, frais de port, notes de frais,
 *    campagnes de publicité, à leur date), sans rien deviner ;
 *  - `projectionWithSales` : la même, plus les encaissements nets : réels jour par jour entre le solde et hier, moyenne des 30 derniers
 *    jours à partir d'aujourd'hui (marqués estimés).
 * L'alerte (vignette `cash-low`, notification `cash_critical`) suit le point bas des 14 prochains jours de la série choisie par
 * `includeEstimatedSales`. Montants en cents, devise du site ; tout en SQL, jamais en cache applicatif.
 */
class GestionCashService
{
    public const DEFAULT_DAYS = 90;
    public const MIN_DAYS = 14;
    public const MAX_DAYS = 365;
    public const ALERT_DAYS = 14;            // point bas surveillé : aujourd'hui et les 13 jours suivants
    public const RECEIPTS_WINDOW_DAYS = 30;  // moyenne des encaissements nets
    public const STALE_DAYS = 7;             // solde ancien au-delà
    public const GROUPED_SALARIES_DAYS = 30; // total des salaires rendu à un lecteur sans ROLE_ADMIN
    public const RECORDED = 'recorded';

    /** Part payée autrement qu'en argent : carte-cadeau, avoir, coupon du fabricant (remboursé plus tard par le fabricant) */
    private const NON_CASH = '(COALESCE(o.gift_card_applied, 0) + COALESCE(o.credit_applied, 0) + COALESCE(o.manufacturer_coupon_applied, 0))';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodCalendar $calendar,
        private readonly StoreClock $clock,
        private readonly ContentAuditRecorder $audit,
        private readonly TranslatorInterface $translator,
        private readonly ?TenantCurrencyProvider $currency = null,
    ) {
    }

    // ----- soldes -----

    /** @return list<array<string, mixed>> du plus récent au plus ancien */
    public function balances(int $limit): array
    {
        $rows = $this->conn()->fetchAllAssociative('SELECT * FROM gestion_cash_balance ORDER BY balance_date DESC, id DESC LIMIT ' . max(1, min(200, $limit)));

        return array_map(fn (array $r) => $this->balanceRow($r), $rows);
    }

    /**
     * Saisie d'un solde : une ligne par date, une date déjà saisie est remplacée.
     *
     * @return array<string, mixed> le solde, avec `replaced`
     */
    public function saveBalance(array $data, ?string $by): array
    {
        $f = $this->validateBalance($data);
        $conn = $this->conn();
        $before = $conn->fetchAssociative('SELECT * FROM gestion_cash_balance WHERE balance_date = ?', [$f['date']]);
        $row = $conn->fetchAssociative(
            'INSERT INTO gestion_cash_balance (balance_date, amount_cents, note, created_by, created_at, updated_at) VALUES (:d, :a, :n, :by, NOW(), NOW())'
            . ' ON CONFLICT (balance_date) DO UPDATE SET amount_cents = EXCLUDED.amount_cents, note = EXCLUDED.note, updated_at = NOW()'
            . ' RETURNING *, (xmax <> 0) AS replaced',
            ['d' => $f['date'], 'a' => $f['amount'], 'n' => $f['note'], 'by' => $by]
        );
        $replaced = (bool) $row['replaced'];
        $out = $this->balanceRow($row);
        $this->audit->record('gestion-tresorerie-soldes', $out['id'], $replaced ? 'update' : 'create', $before === false ? null : $this->balanceRow($before), $out);

        return $out + ['replaced' => $replaced];
    }

    public function updateBalance(int $id, array $data): array
    {
        $current = $this->findBalance($id);
        $f = $this->validateBalance(array_replace(['date' => $current['date'], 'amount' => $current['amount'], 'note' => $current['note']], $data));
        $taken = $this->conn()->fetchOne('SELECT id FROM gestion_cash_balance WHERE balance_date = ? AND id <> ?', [$f['date'], $id]);
        if ($taken !== false) {
            throw new GestionConflictException('BALANCE_DATE_TAKEN', 'Un solde est déjà saisi à cette date.', 409, ['balanceId' => (int) $taken]);
        }
        $this->conn()->executeStatement('UPDATE gestion_cash_balance SET balance_date = ?, amount_cents = ?, note = ?, updated_at = NOW() WHERE id = ?', [$f['date'], $f['amount'], $f['note'], $id]);
        $after = $this->findBalance($id);
        $this->audit->record('gestion-tresorerie-soldes', $id, 'update', $current, $after);

        return $after;
    }

    public function deleteBalance(int $id): void
    {
        $before = $this->findBalance($id);
        $this->conn()->executeStatement('DELETE FROM gestion_cash_balance WHERE id = ?', [$id]);
        $this->audit->record('gestion-tresorerie-soldes', $id, 'delete', $before, null);
    }

    /** Solde de référence : le dernier dont la date tombe au plus tard aujourd'hui (jour du magasin), ou null */
    public function referenceBalance(?\DateTimeImmutable $today = null): ?array
    {
        $today ??= $this->calendar->today();
        $row = $this->conn()->fetchAssociative('SELECT * FROM gestion_cash_balance WHERE balance_date <= ? ORDER BY balance_date DESC LIMIT 1', [$today->format('Y-m-d')]);

        return $row === false ? null : $this->balanceRow($row, $today);
    }

    public function hasBalance(): bool
    {
        return $this->conn()->fetchOne('SELECT 1 FROM gestion_cash_balance LIMIT 1') !== false;
    }

    public function hasMovement(): bool
    {
        return $this->conn()->fetchOne('SELECT 1 FROM gestion_cash_movement LIMIT 1') !== false;
    }

    // ----- mouvements prévus -----

    /**
     * Mouvements prévus ; un lecteur sans ROLE_ADMIN (`$detailSalaries` faux) reçoit les salaires regroupés en une ligne : total des
     * 30 prochains jours, sans libellé ni montant individuel.
     *
     * @return list<array<string, mixed>>
     */
    public function movements(?string $category, string $locale, bool $detailSalaries, ?\DateTimeImmutable $today = null): array
    {
        $today ??= $this->calendar->today();
        if ($category !== null && !in_array($category, GestionCashMovement::CATEGORIES, true)) {
            throw new GestionValidationException(['category' => 'INVALID_CATEGORY']);
        }
        $rows = $category === null
            ? $this->conn()->fetchAllAssociative('SELECT * FROM gestion_cash_movement ORDER BY start_date, id')
            : $this->conn()->fetchAllAssociative('SELECT * FROM gestion_cash_movement WHERE category = ? ORDER BY start_date, id', [$category]);
        $out = [];
        $salaries = ['count' => 0, 'amount' => 0];
        $to = $today->modify('+' . (self::GROUPED_SALARIES_DAYS - 1) . ' days')->format('Y-m-d');
        foreach ($rows as $r) {
            if (!$detailSalaries && $r['category'] === 'salaries') {
                ++$salaries['count'];
                $sign = $r['direction'] === 'in' ? -1 : 1;
                $salaries['amount'] += $sign * (int) $r['amount_cents'] * count(self::occurrences((string) $r['start_date'], (string) $r['recurrence'], $r['end_date'], $today->format('Y-m-d'), $to));

                continue;
            }
            $out[] = $this->movementRow($r, $locale, $today);
        }
        if ($salaries['count'] > 0) {
            $out[] = [
                'id' => null, 'grouped' => true, 'label' => $this->label('salaries', $locale), 'category' => 'salaries', 'categoryLabel' => $this->label('salaries', $locale),
                'direction' => 'out', 'amount' => $salaries['amount'], 'amountPeriodDays' => self::GROUPED_SALARIES_DAYS, 'count' => $salaries['count'],
            ];
        }

        return $out;
    }

    public function createMovement(array $data, ?string $by, string $locale): array
    {
        $f = $this->validateMovement($data);
        $id = (int) $this->conn()->fetchOne(
            'INSERT INTO gestion_cash_movement (label, category, direction, amount_cents, start_date, recurrence, end_date, notes, created_by, created_at, updated_at)'
            . ' VALUES (:label, :category, :direction, :amount, :start, :recurrence, :end, :notes, :by, NOW(), NOW()) RETURNING id',
            $f + ['by' => $by]
        );
        $out = $this->findMovement($id, $locale);
        $this->audit->record('gestion-tresorerie-mouvements', $id, 'create', null, $out);

        return $out;
    }

    public function updateMovement(int $id, array $data, string $locale): array
    {
        $current = $this->findMovement($id, $locale);
        $merged = array_replace([
            'label' => $current['label'], 'category' => $current['category'], 'direction' => $current['direction'], 'amount' => $current['amount'],
            'startDate' => $current['startDate'], 'recurrence' => $current['recurrence'], 'endDate' => $current['endDate'], 'notes' => $current['notes'],
        ], $data);
        if (array_key_exists('date', $data) && !array_key_exists('startDate', $data)) {
            $merged['startDate'] = $data['date'];
        }
        $f = $this->validateMovement($merged);
        $this->conn()->executeStatement(
            'UPDATE gestion_cash_movement SET label = :label, category = :category, direction = :direction, amount_cents = :amount, start_date = :start,'
            . ' recurrence = :recurrence, end_date = :end, notes = :notes, updated_at = NOW() WHERE id = :id',
            $f + ['id' => $id]
        );
        $after = $this->findMovement($id, $locale);
        $this->audit->record('gestion-tresorerie-mouvements', $id, 'update', $current, $after);

        return $after;
    }

    public function deleteMovement(int $id, string $locale): void
    {
        $before = $this->findMovement($id, $locale);
        $this->conn()->executeStatement('DELETE FROM gestion_cash_movement WHERE id = ?', [$id]);
        $this->audit->record('gestion-tresorerie-mouvements', $id, 'delete', $before, null);
    }

    /**
     * Dates (Y-m-d) d'un mouvement comprises dans [from, to]. Mensuel, trimestriel, annuel : même jour du mois que le début, le dernier jour
     * du mois quand il est plus court (31 → 30 ou 28/29 ; 29 février → 28 février les autres années).
     *
     * @return list<string>
     */
    public static function occurrences(string $start, string $recurrence, ?string $end, string $from, string $to): array
    {
        $start = substr($start, 0, 10);
        $last = $end !== null && substr($end, 0, 10) < $to ? substr($end, 0, 10) : $to;
        if ($recurrence === 'none') {
            return $start >= $from && $start <= $last ? [$start] : [];
        }
        $s = new \DateTimeImmutable($start);
        $dates = [];
        if ($recurrence === 'weekly') {
            $gap = (int) $s->diff(new \DateTimeImmutable($from))->format('%r%a');
            for ($k = max(0, intdiv($gap, 7)); ; ++$k) {
                $d = $s->modify('+' . (7 * $k) . ' days')->format('Y-m-d');
                if ($d > $last) {
                    break;
                }
                if ($d >= $from) {
                    $dates[] = $d;
                }
            }

            return $dates;
        }
        $step = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12][$recurrence] ?? null;
        if ($step === null) {
            return [];
        }
        [$y, $m, $day] = array_map('intval', explode('-', $start));
        [$fy, $fm] = array_map('intval', explode('-', $from));
        $monthsToFrom = ($fy - $y) * 12 + ($fm - $m);
        for ($k = max(0, intdiv($monthsToFrom, $step) - 1), $guard = 0; $guard < 2000; ++$k, ++$guard) {
            $total = ($m - 1) + $k * $step;
            $yy = $y + intdiv($total, 12);
            $mm = $total % 12 + 1;
            $dd = min($day, (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $yy, $mm)))->format('t'));
            $d = sprintf('%04d-%02d-%02d', $yy, $mm, $dd);
            if ($d > $last) {
                break;
            }
            if ($d >= $from) {
                $dates[] = $d;
            }
        }

        return $dates;
    }

    // ----- réglages -----

    /** @return array{threshold: int, includeEstimatedSales: bool} */
    public function settings(): array
    {
        $row = $this->conn()->fetchAssociative('SELECT threshold_cents, include_estimated_sales FROM gestion_cash_setting WHERE id = 1');

        return ['threshold' => $row === false ? 0 : (int) $row['threshold_cents'], 'includeEstimatedSales' => $row !== false && (bool) $row['include_estimated_sales']];
    }

    public function putSettings(array $data): array
    {
        $before = $this->settings();
        $input = new GestionInput($data);
        $threshold = $input->signedCents('threshold', false);
        $errors = $input->errors();
        $include = $data['includeEstimatedSales'] ?? null;
        if ($include !== null && !is_bool($include)) {
            $errors['includeEstimatedSales'] = 'MUST_BE_BOOLEAN';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Réglages invalides : ' . implode(', ', array_keys($errors)));
        }
        $after = ['threshold' => $threshold ?? $before['threshold'], 'includeEstimatedSales' => $include ?? $before['includeEstimatedSales']];
        $this->conn()->executeStatement(
            'INSERT INTO gestion_cash_setting (id, threshold_cents, include_estimated_sales, updated_at) VALUES (1, :t, :i, NOW())'
            . ' ON CONFLICT (id) DO UPDATE SET threshold_cents = EXCLUDED.threshold_cents, include_estimated_sales = EXCLUDED.include_estimated_sales, updated_at = NOW()',
            ['t' => $after['threshold'], 'i' => $after['includeEstimatedSales'] ? 'true' : 'false']
        );
        $this->audit->record('gestion-tresorerie-reglages', 1, 'update', $before, $after);

        return $after;
    }

    // ----- projection -----

    /**
     * Projection du solde, du jour du solde de référence jusqu'à aujourd'hui + `$days` − 1. Chaque jour : `in` (mouvements prévus entrants),
     * `out` (mouvements prévus sortants + dépenses saisies de ce jour), `balance` (solde en fin de journée). Le jour du solde ouvre la liste
     * (`opening`) : ce qui est daté ce jour-là est supposé déjà compris dans le solde saisi.
     *
     * @return array<string, mixed>
     */
    public function projection(int $days, string $locale, ?\DateTimeImmutable $today = null): array
    {
        $today ??= $this->calendar->today();
        $todayKey = $today->format('Y-m-d');
        $settings = $this->settings();
        $balance = $this->referenceBalance($today);
        $average = $this->averageReceipts($today);
        $alertSeries = $settings['includeEstimatedSales'] ? 'projectionWithSales' : 'projection';
        $base = ['currency' => $this->siteCurrency(), 'settings' => $settings, 'balance' => $balance, 'estimatedDailyReceipts' => $average, 'alertSeries' => $alertSeries];
        if ($balance === null) {
            return $base + ['projection' => null, 'projectionWithSales' => null, 'status' => 'no_balance', 'byCategory' => [], 'caveats' => []];
        }
        $startKey = $balance['date'];
        $from = (new \DateTimeImmutable($startKey))->modify('+1 day');
        $fromKey = $from->format('Y-m-d');
        $endKey = $today->modify('+' . ($days - 1) . ' days')->format('Y-m-d');

        // mouvements prévus
        $mvIn = [];
        $mvOut = [];
        $byCategory = [];
        foreach ($this->conn()->fetchAllAssociative('SELECT category, direction, amount_cents, start_date, recurrence, end_date FROM gestion_cash_movement') as $r) {
            foreach (self::occurrences((string) $r['start_date'], (string) $r['recurrence'], $r['end_date'], $fromKey, $endKey) as $d) {
                $amount = (int) $r['amount_cents'];
                $byCategory[$r['category']] ??= ['in' => 0, 'out' => 0];
                if ($r['direction'] === 'in') {
                    $mvIn[$d] = ($mvIn[$d] ?? 0) + $amount;
                    $byCategory[$r['category']]['in'] += $amount;
                } else {
                    $mvOut[$d] = ($mvOut[$d] ?? 0) + $amount;
                    $byCategory[$r['category']]['out'] += $amount;
                }
            }
        }
        $recorded = $this->recordedExpenses($fromKey, $endKey);
        $yesterdayKey = $today->modify('-1 day')->format('Y-m-d');
        $actual = $fromKey <= $yesterdayKey ? $this->receiptsByDay($fromKey, $yesterdayKey)['days'] : [];

        $plain = [['date' => $startKey, 'in' => 0, 'out' => 0, 'balance' => $balance['amount'], 'opening' => true]];
        $withSales = [['date' => $startKey, 'in' => 0, 'out' => 0, 'receipts' => 0, 'receiptsEstimated' => false, 'balance' => $balance['amount'], 'opening' => true]];
        $b = $balance['amount'];
        $bs = $balance['amount'];
        $receiptsTotal = 0;
        for ($d = $from; ($k = $d->format('Y-m-d')) <= $endKey; $d = $d->modify('+1 day')) {
            $in = $mvIn[$k] ?? 0;
            $out = ($mvOut[$k] ?? 0) + ($recorded['days'][$k] ?? 0);
            $past = $k < $todayKey;
            $receipts = $past ? ($actual[$k] ?? 0) : $average['amount'];
            $receiptsTotal += $receipts;
            $b += $in - $out;
            $bs += $in - $out + $receipts;
            $plain[] = ['date' => $k, 'in' => $in, 'out' => $out, 'balance' => $b];
            $withSales[] = ['date' => $k, 'in' => $in, 'out' => $out, 'receipts' => $receipts, 'receiptsEstimated' => !$past, 'balance' => $bs];
        }
        $series = [
            'projection' => $this->series($plain, $todayKey, $settings['threshold']),
            'projectionWithSales' => $this->series($withSales, $todayKey, $settings['threshold']) + ['estimated' => true, 'receiptsTotal' => $receiptsTotal],
        ];

        $categories = [];
        foreach (GestionCashMovement::CATEGORIES as $c) {
            if (isset($byCategory[$c])) {
                $categories[] = ['category' => $c, 'label' => $this->label($c, $locale), 'in' => $byCategory[$c]['in'], 'out' => $byCategory[$c]['out']];
            }
        }
        $categories[] = ['category' => self::RECORDED, 'label' => $this->label(self::RECORDED, $locale), 'in' => 0, 'out' => array_sum($recorded['days']), 'details' => $recorded['details']];

        $caveats = [];
        if ($balance['ageDays'] > self::STALE_DAYS) {
            $caveats[] = $this->caveat('STALE_BALANCE', $locale, ['%days%' => $balance['ageDays']]);
        }
        $caveats[] = $this->caveat('RECORDED_EXPENSES_INCLUDED', $locale);
        $caveats[] = $this->caveat('TAXES_INCLUDED', $locale);
        if ($average['orders'] === 0) {
            $caveats[] = $this->caveat('NO_SALES_HISTORY', $locale);
        }

        return $base + $series + [
            'status' => self::status($series[$alertSeries]['low14']['amount'], $settings['threshold']),
            'byCategory' => $categories,
            'caveats' => $caveats,
        ];
    }

    /**
     * État de l'alerte (vignette, notification) : null sans solde saisi.
     *
     * @return array{status: string, series: string, low14: array{date: string, amount: int}, threshold: int, balance: array<string, mixed>}|null
     */
    public function alert(?\DateTimeImmutable $today = null): ?array
    {
        $p = $this->projection(self::ALERT_DAYS, 'fr', $today);
        if ($p['balance'] === null) {
            return null;
        }

        return ['status' => $p['status'], 'series' => $p['alertSeries'], 'low14' => $p[$p['alertSeries']]['low14'], 'threshold' => $p['settings']['threshold'], 'balance' => $p['balance']];
    }

    /**
     * critical : point bas sous le seuil ET sous zéro ; warning : sous le seuil, encore positif ou nul ; ok sinon. Seuil 0 (défaut) : passer
     * sous 0 est critique. Seuil négatif (découvert autorisé) : rien tant qu'on reste au-dessus du découvert.
     */
    public static function status(int $low14, int $threshold): string
    {
        if ($low14 < $threshold) {
            return $low14 < 0 ? 'critical' : 'warning';
        }

        return 'ok';
    }

    /**
     * Encaissements nets par jour du magasin sur [from, to] (inclus) : ventes, locations, abonnements et cartes-cadeaux vendues, total TTC
     * (taxes comprises, livraison comprise, arrondi des espèces compris), moins la part payée par carte-cadeau, avoir ou coupon du fabricant ;
     * moins les retours (remboursés hors avoir et carte-cadeau). Commandes incomplètes (1) et annulées (7) exclues.
     *
     * @return array{days: array<string, int>, orders: int}
     */
    public function receiptsByDay(string $from, string $to): array
    {
        [$start, $end] = $this->clock->range($from, $to);
        $rows = $this->conn()->fetchAllAssociative(
            "SELECT CAST((o.order_date AT TIME ZONE 'UTC') AT TIME ZONE :tz AS date) AS d, COUNT(*) AS n,"
            . ' SUM(CASE WHEN o.order_type_id = 2 THEN -ABS(COALESCE(o.total_amount, 0)) + COALESCE(o.cash_rounding, 0) + ' . self::NON_CASH
            . ' ELSE COALESCE(o.total_amount, 0) + COALESCE(o.cash_rounding, 0) - ' . self::NON_CASH . ' END) AS amount'
            . ' FROM "order" o WHERE o.order_date >= :from AND o.order_date < :to AND o.order_type_id IN (1, 2, 3) AND o.status_id NOT IN (1, 7) GROUP BY 1',
            ['tz' => $this->clock->timezone()->getName(), 'from' => $start->format('Y-m-d H:i:s'), 'to' => $end->format('Y-m-d H:i:s')]
        );
        $days = [];
        $orders = 0;
        foreach ($rows as $r) {
            $days[substr((string) $r['d'], 0, 10)] = (int) round((float) $r['amount']);
            $orders += (int) $r['n'];
        }

        return ['days' => $days, 'orders' => $orders];
    }

    /** @return array{amount: int, windowDays: int, from: string, to: string, orders: int, estimated: bool} moyenne des 30 jours complets avant aujourd'hui */
    public function averageReceipts(\DateTimeImmutable $today): array
    {
        $from = $today->modify('-' . self::RECEIPTS_WINDOW_DAYS . ' days')->format('Y-m-d');
        $to = $today->modify('-1 day')->format('Y-m-d');
        $r = $this->receiptsByDay($from, $to);

        return ['amount' => (int) round(array_sum($r['days']) / self::RECEIPTS_WINDOW_DAYS), 'windowDays' => self::RECEIPTS_WINDOW_DAYS, 'from' => $from, 'to' => $to, 'orders' => $r['orders'], 'estimated' => true];
    }

    /**
     * Dépenses saisies dans l'application, datées dans [from, to], dans la devise du site ; la date de la dépense sert de date de paiement.
     * Commandes fournisseurs (lignes × coût × taux, même règle que les tableaux de bord ; reprise d'historique exclue : ce n'est pas une
     * dépense), leurs frais de port, notes de frais, campagnes de publicité (à leur date de début).
     *
     * @return array{days: array<string, int>, details: array{purchases: int, shipping: int, notes: int, advertising: int}}
     */
    public function recordedExpenses(string $from, string $to): array
    {
        $conn = $this->conn();
        $range = ['from' => $from . ' 00:00:00', 'to' => (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00', 'd1' => $from, 'd2' => $to];
        $queries = [
            'purchases' => "SELECT CAST(c.date AS date) AS d, SUM(l.quantity_ordered * COALESCE(l.unit_cost_cents, p.purchase_price, p.price, 0) * COALESCE(c.exchange_rate, 1)) AS amount FROM commande c JOIN commande_line l ON l.commande_id = c.id LEFT JOIN product p ON p.id = l.product_id WHERE COALESCE(c.kind, '') <> 'reprise' AND c.date >= :from AND c.date < :to GROUP BY 1",
            'shipping' => "SELECT CAST(c.date AS date) AS d, SUM(f.price * COALESCE(c.exchange_rate, 1)) AS amount FROM frais_de_port f JOIN commande c ON c.id = f.commande_id WHERE COALESCE(c.kind, '') <> 'reprise' AND c.date >= :from AND c.date < :to GROUP BY 1",
            'notes' => 'SELECT n.date AS d, SUM(n.montant) AS amount FROM note_de_frais n WHERE n.date >= :d1 AND n.date <= :d2 GROUP BY 1',
            'advertising' => 'SELECT m.start_date AS d, SUM(ROUND(m.amount_cents * COALESCE(m.exchange_rate, 1))) AS amount FROM marketing_campaign m WHERE m.start_date >= :d1 AND m.start_date <= :d2 GROUP BY 1',
        ];
        $days = [];
        $details = [];
        foreach ($queries as $kind => $sql) {
            $details[$kind] = 0;
            foreach ($conn->fetchAllAssociative($sql, $range) as $r) {
                $amount = (int) round((float) $r['amount']);
                $k = substr((string) $r['d'], 0, 10);
                $days[$k] = ($days[$k] ?? 0) + $amount;
                $details[$kind] += $amount;
            }
        }
        ksort($days);

        return ['days' => $days, 'details' => $details];
    }

    // ----- interne -----

    /** Point bas sur l'horizon et sur les 14 prochains jours (à partir d'aujourd'hui), premier jour sous le seuil */
    private function series(array $days, string $todayKey, int $threshold): array
    {
        $alertEnd = (new \DateTimeImmutable($todayKey))->modify('+' . (self::ALERT_DAYS - 1) . ' days')->format('Y-m-d');
        $low = null;
        $low14 = null;
        $below = null;
        foreach ($days as $day) {
            if ($day['date'] < $todayKey) {
                continue;
            }
            if ($low === null || $day['balance'] < $low['amount']) {
                $low = ['date' => $day['date'], 'amount' => $day['balance']];
            }
            if ($day['date'] <= $alertEnd && ($low14 === null || $day['balance'] < $low14['amount'])) {
                $low14 = ['date' => $day['date'], 'amount' => $day['balance']];
            }
            if ($below === null && $day['balance'] < $threshold) {
                $below = $day['date'];
            }
        }
        $last = $days[array_key_last($days)];

        return ['days' => $days, 'low' => $low, 'low14' => $low14, 'belowThresholdFrom' => $below, 'end' => ['date' => $last['date'], 'amount' => $last['balance']]];
    }

    private function validateBalance(array $data): array
    {
        $input = new GestionInput($data);
        $date = $input->date('date', true);
        $amount = $input->signedCents('amount', true);
        $note = $input->text('note', false, 500);
        $errors = $input->errors();
        if ($date !== null && $date->format('Y-m-d') > $this->calendar->today()->format('Y-m-d')) {
            $errors['date'] = 'DATE_IN_FUTURE';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Solde invalide : ' . implode(', ', array_keys($errors)));
        }

        return ['date' => $date->format('Y-m-d'), 'amount' => $amount, 'note' => $note];
    }

    private function validateMovement(array $data): array
    {
        if (!array_key_exists('startDate', $data) && array_key_exists('date', $data)) {
            $data['startDate'] = $data['date'];
        }
        $input = new GestionInput($data);
        $label = $input->text('label', true);
        $amount = $input->signedCents('amount', true);
        $start = $input->date('startDate', true);
        $end = $input->date('endDate', false);
        $notes = $input->text('notes', false, 2000);
        $errors = $input->errors();
        $category = is_string($data['category'] ?? null) ? strtolower(trim($data['category'])) : null;
        if ($category === null || $category === '') {
            $errors['category'] = 'REQUIRED';
        } elseif (!in_array($category, GestionCashMovement::CATEGORIES, true)) {
            $errors['category'] = 'INVALID_CATEGORY';
        }
        $direction = ($data['direction'] ?? null) === null ? 'out' : (is_string($data['direction']) ? strtolower(trim($data['direction'])) : '');
        if (!in_array($direction, GestionCashMovement::DIRECTIONS, true)) {
            $errors['direction'] = 'INVALID_DIRECTION';
        }
        $recurrence = ($data['recurrence'] ?? null) === null ? 'none' : (is_string($data['recurrence']) ? strtolower(trim($data['recurrence'])) : '');
        if (!in_array($recurrence, GestionCashMovement::RECURRENCES, true)) {
            $errors['recurrence'] = 'INVALID_RECURRENCE';
        }
        if ($amount !== null && $amount <= 0) {
            $errors['amount'] = 'MUST_BE_POSITIVE';
        }
        if ($start !== null && $end !== null && $end->format('Y-m-d') < $start->format('Y-m-d')) {
            $errors['endDate'] = 'BEFORE_START';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Mouvement invalide : ' . implode(', ', array_keys($errors)));
        }

        return [
            'label' => $label, 'category' => $category, 'direction' => $direction, 'amount' => $amount, 'start' => $start->format('Y-m-d'),
            'recurrence' => $recurrence, 'end' => $recurrence === 'none' || $end === null ? null : $end->format('Y-m-d'), 'notes' => $notes,
        ];
    }

    private function findBalance(int $id): array
    {
        $row = $this->conn()->fetchAssociative('SELECT * FROM gestion_cash_balance WHERE id = ?', [$id]);
        if ($row === false) {
            throw new GestionConflictException('BALANCE_NOT_FOUND', 'Solde introuvable.', 404, ['balanceId' => $id]);
        }

        return $this->balanceRow($row);
    }

    private function findMovement(int $id, string $locale): array
    {
        $row = $this->conn()->fetchAssociative('SELECT * FROM gestion_cash_movement WHERE id = ?', [$id]);
        if ($row === false) {
            throw new GestionConflictException('MOVEMENT_NOT_FOUND', 'Mouvement introuvable.', 404, ['movementId' => $id]);
        }

        return $this->movementRow($row, $locale, $this->calendar->today());
    }

    private function balanceRow(array $r, ?\DateTimeImmutable $today = null): array
    {
        $today ??= $this->calendar->today();
        $date = substr((string) $r['balance_date'], 0, 10);

        return [
            'id' => (int) $r['id'], 'date' => $date, 'amount' => (int) $r['amount_cents'], 'note' => $r['note'],
            'ageDays' => (int) (new \DateTimeImmutable($date))->diff(new \DateTimeImmutable($today->format('Y-m-d')))->format('%r%a'), // jours entiers, quel que soit le fuseau
            'createdAt' => (new \DateTimeImmutable((string) $r['created_at']))->format(\DateTimeInterface::ATOM),
            'updatedAt' => (new \DateTimeImmutable((string) $r['updated_at']))->format(\DateTimeInterface::ATOM),
        ];
    }

    private function movementRow(array $r, string $locale, \DateTimeImmutable $today): array
    {
        $start = substr((string) $r['start_date'], 0, 10);
        $end = $r['end_date'] === null ? null : substr((string) $r['end_date'], 0, 10);
        $from = max($start, $today->format('Y-m-d'));
        $next = self::occurrences($start, (string) $r['recurrence'], $end, $today->format('Y-m-d'), (new \DateTimeImmutable($from))->modify('+400 days')->format('Y-m-d'))[0] ?? null;

        return [
            'id' => (int) $r['id'], 'label' => (string) $r['label'], 'category' => (string) $r['category'], 'categoryLabel' => $this->label((string) $r['category'], $locale),
            'direction' => (string) $r['direction'], 'amount' => (int) $r['amount_cents'], 'startDate' => $start, 'recurrence' => (string) $r['recurrence'],
            'endDate' => $end, 'notes' => $r['notes'], 'nextDate' => $next,
        ];
    }

    private function caveat(string $code, string $locale, array $params = []): array
    {
        return ['code' => $code, 'text' => $this->translator->trans("cash.caveats.$code", $params, 'gestion', $locale)];
    }

    private function label(string $category, string $locale): string
    {
        return $this->translator->trans("cash.categories.$category", [], 'gestion', $locale);
    }

    private function siteCurrency(): string
    {
        return $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT;
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
