<?php

namespace App\Gestion\Services;

use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sections des statistiques unifiées (lot S2, `docs/statistiques-gestion.md` § 6) : ce que rendaient les anciens écrans de statistiques
 * de l'application, repris dans la route unifiée par le paramètre `sections=`. Sans ce paramètre, la réponse de S1 ne change pas d'un octet.
 * Chaque section suit la plage, la plage précédente et l'an dernier de la demande ; tout est calculé ici, en cents, au fuseau du magasin.
 * Lecture seule.
 *
 * Lot S2-a (14/11/2026) : `payments` (l'encaissé, par moyen de paiement, et les clôtures de caisse) et `expenses` (notes de frais, frais
 * de port d'achat, publicité ; les frais fixes à côté, hors du total). Lot S2-b (14/11/2026) : `stock` (mouvements, valeur et sa courbe
 * depuis les instantanés quotidiens), `shipping` (port facturé aux clients, par transporteur) et `taxes` (taxes collectées, par taxe).
 */
class GestionStatisticsSections
{
    public const PAYMENTS = 'payments';
    public const EXPENSES = 'expenses';
    public const STOCK = 'stock';
    public const SHIPPING = 'shipping';
    public const TAXES = 'taxes';
    public const AVAILABLE = [self::PAYMENTS, self::EXPENSES, self::STOCK, self::SHIPPING, self::TAXES];

    /** `payment_method.id` => code stable rendu aux applications ; un moyen ajouté plus tard sort en `method_<id>` avec son nom */
    private const METHODS = [1 => 'card', 2 => 'cash', 3 => 'paypal'];

    /** Mouvements de caisse hors ventes (`transaction_type.id`, `docs/caisse.md`) => clé rendue (le vocabulaire du rapport de caisse) */
    private const MOVEMENTS = [3 => 'deposits', 6 => 'withdrawals', 8 => 'fundDeposits', 7 => 'fundWithdrawals'];
    private const SOURCE_POS = 2;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly GestionCashService $cash,
        private readonly PurchaseMetricsCalculator $purchases,
        private readonly PeriodResultCalculator $results,
        private readonly StoreClock $clock,
        private readonly TranslatorInterface $translator,
        private readonly GestionAdviceService $advices,
        private readonly ?GestionFixedCostService $fixedCosts = null,
        private readonly ?GestionStockSnapshotService $stock = null,
        private readonly ?HomeTilesService $tiles = null,
    ) {
    }

    /**
     * Sections demandées : `sections=payments,expenses`. Rien ou une valeur vide : aucune (la réponse de S1).
     *
     * @return list<string> dans l'ordre de `AVAILABLE`, sans doublon
     * @throws GestionValidationException 422 `sections` : `INVALID_SECTION`
     */
    public static function parse(mixed $asked): array
    {
        if ($asked === null || $asked === '') {
            return [];
        }
        if (!is_string($asked)) {
            throw new GestionValidationException(['sections' => 'INVALID_SECTION']);
        }
        $names = array_filter(array_map(fn (string $n) => strtolower(trim($n)), explode(',', $asked)), fn (string $n) => $n !== '');
        if ($names === [] || array_diff($names, self::AVAILABLE) !== []) {
            throw new GestionValidationException(['sections' => 'INVALID_SECTION']);
        }

        return array_values(array_filter(self::AVAILABLE, fn (string $n) => in_array($n, $names, true)));
    }

    /**
     * @param list<string> $names
     * @param array{cur: array{0: string, 1: string}, prev: array{0: string, 1: string}|null, ly: array{0: string, 1: string}, buckets: list<array{0: string, 1: string, 2: string}>,
     *              granularity: string, source: ?int, sourceKey: string, locale: string, today: \DateTimeImmutable} $ctx plages (jours du magasin inclus), tranches du graphique jusqu'à aujourd'hui
     *
     * @return array<string, array<string, mixed>> nom de la section => section
     */
    public function build(array $names, array $ctx): array
    {
        $out = [];
        foreach ($names as $name) {
            $out[$name] = match ($name) {
                self::PAYMENTS => $this->payments($ctx),
                self::EXPENSES => $this->expenses($ctx),
                self::STOCK => $this->stockSection($ctx),
                self::SHIPPING => $this->shipping($ctx),
                self::TAXES => $this->taxes($ctx),
            };
        }

        return $out;
    }

    // ------------------------------------------------------------------ paiements

    /**
     * L'encaissé : ce qui entre, taxes comprises, daté par la date de la commande (la règle de « Ma journée » et de la trésorerie,
     * `GestionCashService::receiptsByDay`). Sur « aujourd'hui », `net` égale `receipts.total` de « Ma journée » au cent près.
     */
    private function payments(array $ctx): array
    {
        [$cur, $prev, $ly, $source, $locale] = [$ctx['cur'], $ctx['prev'], $ctx['ly'], $ctx['source'], $ctx['locale']];
        $days = $this->cash->receiptsDetailByDay($cur[0], $cur[1], $source);
        $daysPrev = $prev === null ? null : $this->cash->receiptsDetailByDay($prev[0], $prev[1], $source);
        $daysLy = $this->cash->receiptsDetailByDay($ly[0], $ly[1], $source);
        $sum = static fn (?array $rows, string $k): ?int => $rows === null ? null : array_sum(array_column($rows, $k));
        $hasLy = $daysLy !== [];

        $points = [];
        $series = [];
        foreach ($ctx['buckets'] as [$key, $from, $to]) {
            $p = self::slice($days, $from, $to);
            $l = self::slice($daysLy, GestionStatisticsService::shiftYears($from, -1), GestionStatisticsService::shiftYears($to, -1));
            $point = ['received' => $sum($p, 'received'), 'refunded' => $sum($p, 'refunded'), 'net' => $sum($p, 'net')];
            $points[] = ['key' => $key, 'from' => $from, 'to' => $to] + $point + ['lastYear' => ['received' => $sum($l, 'received'), 'refunded' => $sum($l, 'refunded'), 'net' => $sum($l, 'net')]];
            $series[] = $point;
        }
        $indicator = fn (string $k) => GestionStatisticsService::indicator($sum($days, $k), $sum($daysPrev, $k), $hasLy ? $sum($daysLy, $k) : null, 'money', array_column($series, $k), $prev !== null);
        $net = $sum($days, 'net');

        // Par moyen de paiement : les lignes de paiement réussies ; ce qu'elles n'expliquent pas (vente sans ligne de paiement) sort en « autre »
        $methods = $this->cash->receiptsByMethod($cur[0], $cur[1], $source);
        $methodsPrev = $prev === null ? null : $this->cash->receiptsByMethod($prev[0], $prev[1], $source);
        $methodsLy = $hasLy ? $this->cash->receiptsByMethod($ly[0], $ly[1], $source) : null;
        $share = static fn (int $amount): ?float => $net > 0 ? round($amount / $net * 100, 1) : null;
        $breakdown = [];
        foreach ($this->methodNames($locale, array_keys($methods + ($methodsPrev ?? []) + ($methodsLy ?? []))) as $id => [$code, $label]) {
            $m = $methods[$id] ?? ['received' => 0, 'refunded' => 0, 'net' => 0];
            $breakdown[] = ['method' => $code, 'label' => $label, 'received' => $m['received'], 'refunded' => $m['refunded'], 'net' => $m['net'], 'share' => $share($m['net']),
                'previousNet' => $methodsPrev === null ? null : ($methodsPrev[$id]['net'] ?? 0), 'lastYearNet' => $methodsLy === null ? null : ($methodsLy[$id]['net'] ?? 0)];
        }
        $caveats = [$this->caveat('PAYMENTS_BY_ORDER_DATE', $locale)];
        $unmatched = $net - array_sum(array_column($methods, 'net'));
        if ($unmatched !== 0) {
            $breakdown[] = ['method' => 'other', 'label' => $this->label('methods.other', $locale), 'received' => null, 'refunded' => null, 'net' => $unmatched, 'share' => $share($unmatched),
                'previousNet' => null, 'lastYearNet' => null];
            // positif : de l'encaissé sans ligne de paiement (vente sans paiement enregistré) ; négatif : des remboursements sans ligne de
            // paiement (un retour sans fiche de caisse, par exemple ; depuis le lot AVOIRS un retour rendu en avoir client ne sort plus de
            // l'encaissé). `amount` garde son signe, le texte dit le montant sans signe
            $caveats[] = $this->caveat($unmatched > 0 ? 'PAYMENTS_UNMATCHED' : 'REFUNDS_UNMATCHED', $locale, ['%amount%' => $this->advices->format(abs($unmatched), 'money', $locale)]) + ['amount' => $unmatched];
        }

        return [
            'indicators' => ['received' => $indicator('received'), 'refunded' => $indicator('refunded'), 'net' => $indicator('net')],
            'breakdown' => $breakdown,
            // Clôtures de caisse : la caisse seulement ; sans objet pour la boutique en ligne et l'application
            'cashDesk' => $source === null || $source === self::SOURCE_POS ? $this->cashDesk($cur, $prev, $ly) : null,
            'chart' => ['granularity' => $ctx['granularity'], 'points' => $points],
            'caveats' => $caveats,
            'appliesSource' => true,
        ] + $this->nonCash($cur, $source, $locale);
    }

    /**
     * Ce qui s'est payé ou rendu autrement qu'en argent (lot AVOIRS, 14/11/2026) : des lignes à part, hors de « reçu », « remboursé » et
     * « net ». Avoirs clients émis, montants revenus sur des cartes-cadeaux, ventes payées par avoir client ou par carte-cadeau ; et deux
     * états du moment, « à ce jour » : avoirs clients en cours et cartes-cadeaux non dépensées (ce que le magasin doit encore en
     * marchandise). La caisse seule en produit : null pour la boutique en ligne et l'application, comme `cashDesk`.
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function nonCash(array $cur, ?int $source, string $locale): array
    {
        $keys = ['storeCreditIssued', 'giftCardRecredited', 'paidByStoreCredit', 'paidByGiftCard', 'outstandingStoreCredit', 'outstandingGiftCards'];
        if ($source !== null && $source !== self::SOURCE_POS) {
            return array_fill_keys($keys, null);
        }
        $flows = $this->cash->nonCashFlows($cur[0], $cur[1]);
        $now = $this->cash->outstandingCredit();
        $out = [];
        foreach ($flows as $key => $flow) {
            $out[$key] = $flow + ['label' => $this->label('payments.' . $key, $locale)];
        }
        foreach (['outstandingStoreCredit' => 'storeCredit', 'outstandingGiftCards' => 'giftCards'] as $key => $from) {
            $out[$key] = $now[$from] + ['asOf' => $now['asOf'], 'label' => $this->label('payments.' . $key, $locale)];
        }

        return $out;
    }

    /**
     * Clôtures de caisse de la plage (rapports de fermeture, `cash_register_report`) : leur nombre, la somme des écarts de caisse
     * (compté − attendu, signée), le nombre de clôtures avec un écart, les dépôts en banque ; et les mouvements de fond hors ventes.
     * Ce que la liste des transactions de la caisse ouverte ne disait pas : elle reste un outil de la caisse.
     */
    private function cashDesk(array $cur, ?array $prev, array $ly): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $closings = function (array $range) use ($conn): array {
            [$start, $end] = $this->clock->range($range[0], $range[1]);
            $row = $conn->fetchAssociative(
                'SELECT COUNT(*) AS closings, COALESCE(SUM(discrepancy), 0) AS discrepancy, COUNT(*) FILTER (WHERE COALESCE(discrepancy, 0) <> 0) AS with_discrepancy, COALESCE(SUM(bank_deposit), 0) AS deposits'
                . ' FROM cash_register_report WHERE closed_at >= :from AND closed_at < :to',
                ['from' => $start->format('Y-m-d H:i:s'), 'to' => $end->format('Y-m-d H:i:s')]
            ) ?: [];

            return ['closings' => (int) ($row['closings'] ?? 0), 'discrepancy' => (int) ($row['discrepancy'] ?? 0), 'discrepancyCount' => (int) ($row['with_discrepancy'] ?? 0), 'bankDeposits' => (int) ($row['deposits'] ?? 0)];
        };
        $c = $closings($cur);
        $p = $prev === null ? null : $closings($prev);
        $l = $closings($ly);
        $hasLy = $l['closings'] > 0;
        $indicator = fn (string $k, string $unit) => GestionStatisticsService::indicator($c[$k], $p[$k] ?? null, $hasLy ? $l[$k] : null, $unit, null, $prev !== null);

        [$start, $end] = $this->clock->range($cur[0], $cur[1]);
        $movements = array_fill_keys(array_values(self::MOVEMENTS), 0);
        foreach ($conn->fetchAllKeyValue(
            'SELECT transaction_type_id, SUM(ABS(COALESCE(cash_amount, ROUND(amount)))) FROM transaction_caisse'
            . ' WHERE transaction_date >= :from AND transaction_date < :to AND transaction_type_id IN (' . implode(', ', array_keys(self::MOVEMENTS)) . ') GROUP BY 1',
            ['from' => $start->format('Y-m-d H:i:s'), 'to' => $end->format('Y-m-d H:i:s')]
        ) as $type => $amount) {
            $movements[self::MOVEMENTS[(int) $type]] = (int) round((float) $amount);
        }

        return ['closings' => $indicator('closings', 'count'), 'discrepancy' => $indicator('discrepancy', 'money'), 'discrepancyCount' => $c['discrepancyCount'],
            'bankDeposits' => $indicator('bankDeposits', 'money'), 'movements' => $movements];
    }

    /**
     * @param list<int> $ids moyens présents dans les paiements lus
     *
     * @return array<int, array{0: string, 1: string}> identifiant => [code, libellé], par identifiant
     */
    private function methodNames(string $locale, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        $names = $ids === [] ? [] : $this->emProvider->getEntityManager()->getConnection()->fetchAllKeyValue('SELECT id, name FROM payment_method WHERE id IN (' . implode(', ', array_map('intval', $ids)) . ')');
        $out = [];
        foreach ($ids as $id) {
            $code = self::METHODS[$id] ?? 'method_' . $id;
            $out[$id] = [$code, isset(self::METHODS[$id]) ? $this->label('methods.' . $code, $locale) : (string) ($names[$id] ?? $code)];
        }

        return $out;
    }

    // ------------------------------------------------------------------ frais

    /**
     * Les frais de l'entreprise : notes de frais, frais de port d'achat et publicité de la plage, avec la règle du résultat de période
     * (`PeriodResultCalculator`) : sur un `periodId`, `total` égale son `expenses.total` au cent près. Les frais fixes sont rendus à
     * côté, HORS du total : ils sont répartis au prorata des jours et ne sont déduits que dans le résultat après frais fixes.
     */
    private function expenses(array $ctx): array
    {
        [$cur, $prev, $ly, $locale] = [$ctx['cur'], $ctx['prev'], $ctx['ly'], $ctx['locale']];
        $totals = function (array $range): array {
            $purchases = $this->purchases->expensesBetween($range[0], $range[1]);
            $advertising = $this->results->advertisingBetween($range[0], $range[1]);

            return ['notes' => $purchases['expenseCost'], 'purchaseShipping' => $purchases['shippingCost'], 'advertising' => $advertising['amount'],
                'total' => $purchases['expenseCost'] + $purchases['shippingCost'] + $advertising['amount'], 'campaigns' => $advertising['campaigns']];
        };
        $t = $totals($cur);
        $tp = $prev === null ? null : $totals($prev);
        $tl = $totals($ly);
        $hasLy = $tl['total'] !== 0;

        $days = $this->expenseDays($cur[0], $cur[1], $t['campaigns']);
        $daysLy = $hasLy ? $this->expenseDays($ly[0], $ly[1], $tl['campaigns']) : [];
        $sum = static fn (array $rows, string $k): int => (int) round(array_sum(array_column($rows, $k)));
        $points = [];
        $series = [];
        foreach ($ctx['buckets'] as [$key, $from, $to]) {
            $p = self::slice($days, $from, $to);
            $l = self::slice($daysLy, GestionStatisticsService::shiftYears($from, -1), GestionStatisticsService::shiftYears($to, -1));
            $point = ['notes' => $sum($p, 'notes'), 'purchaseShipping' => $sum($p, 'purchaseShipping'), 'advertising' => $sum($p, 'advertising')];
            $point = ['total' => array_sum($point)] + $point;
            $points[] = ['key' => $key, 'from' => $from, 'to' => $to] + $point + ['lastYear' => ['total' => $sum($l, 'notes') + $sum($l, 'purchaseShipping') + $sum($l, 'advertising')]];
            $series[] = $point;
        }
        $indicator = fn (string $k) => GestionStatisticsService::indicator($t[$k], $tp[$k] ?? null, $hasLy ? $tl[$k] : null, 'money', array_column($series, $k), $prev !== null);
        $share = static fn (int $amount, int $of): ?float => $of > 0 ? round($amount / $of * 100, 1) : null;

        $caveats = [];
        if ($ctx['sourceKey'] !== 'all') {
            $caveats[] = $this->caveat('SOURCE_NOT_APPLICABLE', $locale);
        }
        $fixed = null;
        if ($this->fixedCosts !== null) {
            $f = $this->fixedCosts->between($cur[0], $cur[1], $locale);
            $fp = $prev === null ? null : $this->fixedCosts->between($prev[0], $prev[1], $locale)['total'];
            $fl = $this->fixedCosts->between($ly[0], $ly[1], $locale)['total'];
            $fixed = GestionStatisticsService::indicator($f['total'], $fp, $fl !== 0 ? $fl : null, 'money', null, $prev !== null) + ['byCategory' => $f['byCategory'], 'days' => $f['days'], 'includedInTotal' => false];
            if ($f['total'] !== 0) {
                $caveats[] = $this->caveat('FIXED_COSTS_OUTSIDE_TOTAL', $locale);
                array_push($caveats, ...$f['caveats']);
            }
        }

        return [
            'indicators' => ['total' => $indicator('total'), 'notes' => $indicator('notes'), 'purchaseShipping' => $indicator('purchaseShipping'), 'advertising' => $indicator('advertising')],
            'breakdown' => array_map(fn (string $kind) => ['kind' => $kind, 'label' => $this->label('expenses.' . $kind, $locale), 'amount' => $t[$kind], 'share' => $share($t[$kind], $t['total'])], ['notes', 'purchaseShipping', 'advertising']),
            'notesByType' => array_map(fn (array $r) => $r + ['share' => $share($r['amount'], $t['notes'])], $this->notesByType($cur[0], $cur[1], $locale)),
            'campaigns' => $t['campaigns'],
            'fixedCosts' => $fixed,
            'chart' => ['granularity' => $ctx['granularity'], 'points' => $points],
            'caveats' => $caveats,
            'appliesSource' => false,
        ];
    }

    /**
     * Frais par jour (pour le graphique) : notes de frais à leur date, frais de port d'achat à la date de leur commande fournisseur (avec
     * son taux de change, valeur non arrondie), campagnes à leur date de début.
     *
     * @param list<array{amount: int, startDate: string}> $campaigns
     *
     * @return array<string, array{notes: float, purchaseShipping: float, advertising: float}>
     */
    private function expenseDays(string $from, string $to, array $campaigns): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $params = ['from' => $from . ' 00:00:00', 'to' => (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00')];
        $days = [];
        $add = function (string $day, string $key, float $amount) use (&$days): void {
            $days[$day] ??= ['notes' => 0.0, 'purchaseShipping' => 0.0, 'advertising' => 0.0];
            $days[$day][$key] += $amount;
        };
        foreach ($conn->fetchAllKeyValue("SELECT to_char(n.date, 'YYYY-MM-DD'), SUM(n.montant) FROM note_de_frais n WHERE n.date >= :from AND n.date < :to GROUP BY 1", $params) as $day => $amount) {
            $add((string) $day, 'notes', (float) $amount);
        }
        foreach ($conn->fetchAllKeyValue("SELECT to_char(c.date, 'YYYY-MM-DD'), SUM(f.price * COALESCE(c.exchange_rate, 1)) FROM frais_de_port f JOIN commande c ON c.id = f.commande_id WHERE c.date >= :from AND c.date < :to GROUP BY 1", $params) as $day => $amount) {
            $add((string) $day, 'purchaseShipping', (float) $amount);
        }
        foreach ($campaigns as $campaign) {
            $add($campaign['startDate'], 'advertising', (float) $campaign['amount']);
        }

        return $days;
    }

    /** @return list<array{typeId: ?int, name: string, amount: int}> notes de frais de la plage par type, du plus gros au plus petit */
    private function notesByType(string $from, string $to, string $locale): array
    {
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT n.type_note_de_frais_id AS id, t.name, SUM(n.montant) AS amount FROM note_de_frais n LEFT JOIN type_note_de_frais t ON t.id = n.type_note_de_frais_id'
            . ' WHERE n.date >= :from AND n.date < :to GROUP BY 1, 2 ORDER BY SUM(n.montant) DESC, n.type_note_de_frais_id',
            ['from' => $from . ' 00:00:00', 'to' => (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00')]
        );

        return array_map(fn (array $r) => ['typeId' => $r['id'] === null ? null : (int) $r['id'], 'name' => $r['name'] === null ? $this->label('expenses.untyped', $locale) : $this->referential((string) $r['name'], $locale),
            'amount' => (int) $r['amount']], $rows);
    }

    // ------------------------------------------------------------------ stock

    /**
     * Le stock : les mouvements de la plage par type (entrées, sorties, retours, ajustements, réceptions fournisseur : les totaux de
     * l'ancienne route `stock-evolution`, sur la plage choisie), la valeur du stock aujourd'hui et sa courbe, lue dans les instantanés
     * quotidiens (`gestion_stock_snapshot`, pris depuis le 11/11/2026 : avant le premier instantané il n'y a rien à tracer).
     */
    private function stockSection(array $ctx): array
    {
        [$cur, $prev, $ly, $locale] = [$ctx['cur'], $ctx['prev'], $ctx['ly'], $ctx['locale']];
        $days = $this->movementDays($cur[0], $cur[1]);
        $daysPrev = $prev === null ? null : $this->movementDays($prev[0], $prev[1]);
        $daysLy = $this->movementDays($ly[0], $ly[1]);
        $hasLy = $daysLy !== [];
        $codes = array_column(\App\Services\StockEvolutionService\StockEvolutionService::TYPES, 1);
        $sum = static fn (?array $rows, string $code): ?int => $rows === null ? null : array_sum(array_column($rows, $code));

        $conn = $this->emProvider->getEntityManager()->getConnection();
        $snapshots = [];
        foreach ($conn->fetchAllAssociative('SELECT snapshot_date, sale_value_cents, cost_value_cents FROM gestion_stock_snapshot WHERE snapshot_date >= ? AND snapshot_date <= ? ORDER BY snapshot_date', [$cur[0], $cur[1]]) as $r) {
            $snapshots[substr((string) $r['snapshot_date'], 0, 10)] = ['saleValue' => (int) $r['sale_value_cents'], 'costValue' => (int) $r['cost_value_cents']];
        }
        $first = $conn->fetchOne('SELECT MIN(snapshot_date) FROM gestion_stock_snapshot');
        $first = $first === null || $first === false ? null : substr((string) $first, 0, 10);

        $points = [];
        $series = [];
        foreach ($ctx['buckets'] as [$key, $from, $to]) {
            $p = self::slice($days, $from, $to);
            $l = self::slice($daysLy, GestionStatisticsService::shiftYears($from, -1), GestionStatisticsService::shiftYears($to, -1));
            $point = [];
            $last = [];
            foreach ($codes as $code) {
                $point[$code] = $sum($p, $code);
                $last[$code] = $sum($l, $code);
            }
            // valeur du stock à la fin de la tranche : le dernier instantané pris dans la tranche, null s'il n'y en a pas
            $inBucket = self::slice($snapshots, $from, $to);
            $value = $inBucket === [] ? ['saleValue' => null, 'costValue' => null] : $inBucket[max(array_keys($inBucket))];
            $points[] = ['key' => $key, 'from' => $from, 'to' => $to] + $point + $value + ['lastYear' => $last];
            $series[] = $point;
        }
        $indicator = fn (string $code) => GestionStatisticsService::indicator($sum($days, $code), $sum($daysPrev, $code), $hasLy ? $sum($daysLy, $code) : null, 'count', array_column($series, $code), $prev !== null);

        $caveats = [];
        if ($ctx['sourceKey'] !== 'all') {
            $caveats[] = $this->caveat('SOURCE_NOT_APPLICABLE', $locale);
        }
        if ($first === null) {
            $caveats[] = $this->caveat('STOCK_HISTORY_EMPTY', $locale);
        } elseif ($cur[0] < $first) {
            $caveats[] = $this->caveat('STOCK_HISTORY_STARTS', $locale, ['%date%' => (new \DateTimeImmutable($first))->format($locale === 'en' ? 'Y-m-d' : 'd/m/Y')]) + ['date' => $first];
        }
        $now = $this->stock?->current($ctx['today']);

        return [
            'indicators' => ['unitsIn' => $indicator('in'), 'unitsOut' => $indicator('out'), 'receptions' => $indicator('reception'), 'adjustments' => $indicator('adjustment'), 'returns' => $indicator('return')],
            'breakdown' => array_map(fn (string $code) => ['code' => $code, 'label' => $this->label('stock.' . $code, $locale), 'quantity' => $sum($days, $code), 'previous' => $sum($daysPrev, $code),
                'lastYear' => $hasLy ? $sum($daysLy, $code) : null], $codes),
            'value' => ['saleValue' => $now['saleValue'] ?? null, 'costValue' => $now['costValue'] ?? null, 'historyStartsAt' => $first],
            'lowCount' => $this->tiles === null ? null : count($this->tiles->lowStock()['all']),
            'chart' => ['granularity' => $ctx['granularity'], 'points' => $points],
            'caveats' => $caveats,
            'appliesSource' => false,
        ];
    }

    /** @return array<string, array<string, int>> jour du magasin => quantités par code de mouvement (les cinq codes, à 0 si rien) */
    private function movementDays(string $from, string $to): array
    {
        [$start, $end] = $this->clock->range($from, $to);
        $types = \App\Services\StockEvolutionService\StockEvolutionService::TYPES;
        $empty = array_fill_keys(array_column($types, 1), 0);
        $days = [];
        foreach ($this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT CAST((movement_date AT TIME ZONE 'UTC') AT TIME ZONE :tz AS date) AS d, movement_type_id AS t, COALESCE(SUM(quantity), 0) AS q FROM inventory_movements"
            . ' WHERE movement_date >= :from AND movement_date < :to AND movement_type_id IS NOT NULL GROUP BY 1, 2',
            ['tz' => $this->clock->timezone()->getName(), 'from' => $start->format('Y-m-d H:i:s'), 'to' => $end->format('Y-m-d H:i:s')]
        ) as $r) {
            if (isset($types[(int) $r['t']])) {
                $day = substr((string) $r['d'], 0, 10);
                $days[$day] ??= $empty;
                $days[$day][$types[(int) $r['t']][1]] += (int) $r['q'];
            }
        }

        return $days;
    }

    // ------------------------------------------------------------------ livraison

    /**
     * La livraison facturée aux clients (`order.shipping_cost`), la règle de `sales.shippingBilled` du résultat de période : ventes et
     * retours (un port rendu se déduit), commandes incomplètes et annulées exclues, datée par la commande. `orders` : les ventes avec
     * un transporteur. Par transporteur, cette fois réel (l'ancienne route `carrier/statistics` ne détaillait pas). Le coût réel des
     * étiquettes n'est pas enregistré par étiquette : la marge sur le port n'est pas calculable ici.
     */
    private function shipping(array $ctx): array
    {
        [$cur, $prev, $ly, $source, $locale] = [$ctx['cur'], $ctx['prev'], $ctx['ly'], $ctx['source'], $ctx['locale']];
        $days = $this->shippingDays($cur[0], $cur[1], $source);
        $daysPrev = $prev === null ? null : $this->shippingDays($prev[0], $prev[1], $source);
        $daysLy = $this->shippingDays($ly[0], $ly[1], $source);
        $hasLy = $daysLy !== [];
        $billed = static fn (?array $rows): ?int => $rows === null ? null : (int) round(array_sum(array_column($rows, 'billed')));
        $orders = static fn (?array $rows): ?int => $rows === null ? null : array_sum(array_column($rows, 'orders'));
        $average = static fn (?int $amount, ?int $count): ?int => $amount === null || !$count ? null : (int) round($amount / $count);

        $points = [];
        $series = [];
        foreach ($ctx['buckets'] as [$key, $from, $to]) {
            $p = self::slice($days, $from, $to);
            $l = self::slice($daysLy, GestionStatisticsService::shiftYears($from, -1), GestionStatisticsService::shiftYears($to, -1));
            $point = ['billed' => $billed($p), 'orders' => $orders($p)];
            $points[] = ['key' => $key, 'from' => $from, 'to' => $to] + $point + ['lastYear' => ['billed' => $billed($l), 'orders' => $orders($l)]];
            $series[] = $point + ['averagePerOrder' => $average($point['billed'], $point['orders'])];
        }
        $total = $billed($days);
        $indicators = [
            'billed' => GestionStatisticsService::indicator($total, $billed($daysPrev), $hasLy ? $billed($daysLy) : null, 'money', array_column($series, 'billed'), $prev !== null),
            'orders' => GestionStatisticsService::indicator($orders($days), $orders($daysPrev), $hasLy ? $orders($daysLy) : null, 'count', array_column($series, 'orders'), $prev !== null),
            'averagePerOrder' => GestionStatisticsService::indicator($average($total, $orders($days)), $average($billed($daysPrev), $orders($daysPrev)), $hasLy ? $average($billed($daysLy), $orders($daysLy)) : null, 'money',
                array_column($series, 'averagePerOrder'), $prev !== null),
        ];

        [$start, $end] = $this->clock->range($cur[0], $cur[1]);
        $breakdown = [];
        foreach ($this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT o.carrier_id AS id, c.name, c.kind, SUM(' . self::SHIPPING_BILLED . ') AS billed, COUNT(*) FILTER (WHERE o.order_type_id = 1 AND o.carrier_id IS NOT NULL) AS orders'
            . ' FROM "order" o LEFT JOIN carrier c ON c.id = o.carrier_id WHERE ' . self::SHIPPING_WHERE . ($source === null ? '' : ' AND o.order_source_id = :src')
            . ' GROUP BY o.carrier_id, c.name, c.kind HAVING SUM(' . self::SHIPPING_BILLED . ') <> 0 OR COUNT(*) FILTER (WHERE o.order_type_id = 1 AND o.carrier_id IS NOT NULL) > 0'
            . ' ORDER BY SUM(' . self::SHIPPING_BILLED . ') DESC, o.carrier_id',
            ['from' => $start->format('Y-m-d H:i:s'), 'to' => $end->format('Y-m-d H:i:s')] + ($source === null ? [] : ['src' => $source])
        ) as $r) {
            $amount = (int) round((float) $r['billed']);
            $breakdown[] = ['carrierId' => $r['id'] === null ? null : (int) $r['id'], 'name' => $r['id'] === null ? $this->label('shipping.none', $locale) : (string) $r['name'], 'kind' => $r['kind'],
                'billed' => $amount, 'orders' => (int) $r['orders'], 'share' => $total > 0 ? round($amount / $total * 100, 1) : null];
        }

        return ['indicators' => $indicators, 'breakdown' => $breakdown, 'chart' => ['granularity' => $ctx['granularity'], 'points' => $points],
            'caveats' => [$this->caveat('SHIPPING_BILLED_NOT_COST', $locale)], 'appliesSource' => true];
    }

    /** Port facturé d'une commande : un retour le déduit (expression de `PeriodResultCalculator::compute`) */
    private const SHIPPING_BILLED = 'CASE WHEN o.order_type_id = 2 THEN -ABS(COALESCE(o.shipping_cost, 0)) ELSE COALESCE(o.shipping_cost, 0) END';
    private const SHIPPING_WHERE = 'o.order_date >= :from AND o.order_date < :to AND o.order_type_id IN (1, 2) AND o.status_id NOT IN (1, 7)';

    /** @return array<string, array{billed: float, orders: int}> jour du magasin => port facturé (non arrondi) et ventes avec transporteur */
    private function shippingDays(string $from, string $to, ?int $source): array
    {
        [$start, $end] = $this->clock->range($from, $to);
        $days = [];
        foreach ($this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT CAST((o.order_date AT TIME ZONE 'UTC') AT TIME ZONE :tz AS date) AS d, SUM(" . self::SHIPPING_BILLED . ') AS billed, COUNT(*) FILTER (WHERE o.order_type_id = 1 AND o.carrier_id IS NOT NULL) AS orders'
            . ' FROM "order" o WHERE ' . self::SHIPPING_WHERE . ($source === null ? '' : ' AND o.order_source_id = :src') . ' GROUP BY 1'
            . ' HAVING SUM(' . self::SHIPPING_BILLED . ') <> 0 OR COUNT(*) FILTER (WHERE o.order_type_id = 1 AND o.carrier_id IS NOT NULL) > 0',
            ['tz' => $this->clock->timezone()->getName(), 'from' => $start->format('Y-m-d H:i:s'), 'to' => $end->format('Y-m-d H:i:s')] + ($source === null ? [] : ['src' => $source])
        ) as $r) {
            $days[substr((string) $r['d'], 0, 10)] = ['billed' => (float) $r['billed'], 'orders' => (int) $r['orders']];
        }

        return $days;
    }

    // ------------------------------------------------------------------ taxes

    /**
     * Les taxes collectées : les lignes de taxe des commandes (`order_tax`) de la plage, telles qu'enregistrées (celles d'un retour sont
     * négatives), avec la règle des statistiques : ventes et retours, commandes incomplètes et annulées exclues, datées par la commande.
     * Par taxe (code, libellé, juridiction, taux), ce que l'ancienne route `taxes/monthly` ne donnait pas. Aide à préparer une
     * déclaration ; ce n'en est pas une.
     */
    private function taxes(array $ctx): array
    {
        [$cur, $prev, $ly, $source, $locale] = [$ctx['cur'], $ctx['prev'], $ctx['ly'], $ctx['source'], $ctx['locale']];
        $days = $this->taxDays($cur[0], $cur[1], $source);
        $daysPrev = $prev === null ? null : $this->taxDays($prev[0], $prev[1], $source);
        $daysLy = $this->taxDays($ly[0], $ly[1], $source);
        $hasLy = $daysLy !== [];
        $sum = static fn (?array $rows): ?int => $rows === null ? null : (int) round(array_sum($rows));

        $points = [];
        $series = [];
        foreach ($ctx['buckets'] as [$key, $from, $to]) {
            $collected = $sum(self::slice($days, $from, $to));
            $points[] = ['key' => $key, 'from' => $from, 'to' => $to, 'collected' => $collected,
                'lastYear' => ['collected' => $sum(self::slice($daysLy, GestionStatisticsService::shiftYears($from, -1), GestionStatisticsService::shiftYears($to, -1)))]];
            $series[] = $collected;
        }
        $total = $sum($days);

        [$start, $end] = $this->clock->range($cur[0], $cur[1]);
        $breakdown = [];
        foreach ($this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT COALESCE(t.tax_code, x.code) AS code, COALESCE(t.tax_label, x.name) AS label, t.tax_jurisdiction AS jurisdiction, t.tax_rate_ppm AS rate, SUM(t.amount) AS collected, SUM(t.base) AS base'
            . ' FROM order_tax t JOIN "order" o ON o.id = t.order_tax_id LEFT JOIN tax x ON x.id = t.tax_id WHERE ' . self::TAX_WHERE . ($source === null ? '' : ' AND o.order_source_id = :src')
            . ' GROUP BY 1, 2, 3, 4 ORDER BY SUM(t.amount) DESC, 1, 2',
            ['from' => $start->format('Y-m-d H:i:s'), 'to' => $end->format('Y-m-d H:i:s')] + ($source === null ? [] : ['src' => $source])
        ) as $r) {
            $amount = (int) round((float) $r['collected']);
            $breakdown[] = ['code' => $r['code'], 'label' => $r['label'] ?? $this->label('taxes.unnamed', $locale), 'jurisdiction' => $r['jurisdiction'], 'ratePpm' => $r['rate'] === null ? null : (int) $r['rate'],
                'base' => $r['base'] === null ? null : (int) $r['base'], 'collected' => $amount, 'share' => $total > 0 ? round($amount / $total * 100, 1) : null];
        }

        return [
            'indicators' => ['collected' => GestionStatisticsService::indicator($total, $sum($daysPrev), $hasLy ? $sum($daysLy) : null, 'money', $series, $prev !== null)],
            'breakdown' => $breakdown,
            'chart' => ['granularity' => $ctx['granularity'], 'points' => $points],
            'caveats' => [$this->caveat('TAXES_NOT_A_RETURN', $locale)],
            'appliesSource' => true,
        ];
    }

    private const TAX_WHERE = 'o.order_date >= :from AND o.order_date < :to AND o.order_type_id IN (1, 2) AND o.status_id NOT IN (1, 7)';

    /** @return array<string, float> jour du magasin => taxes collectées (non arrondies) */
    private function taxDays(string $from, string $to, ?int $source): array
    {
        [$start, $end] = $this->clock->range($from, $to);
        $days = [];
        foreach ($this->emProvider->getEntityManager()->getConnection()->fetchAllKeyValue(
            "SELECT CAST((o.order_date AT TIME ZONE 'UTC') AT TIME ZONE :tz AS date) AS d, SUM(t.amount) FROM order_tax t JOIN \"order\" o ON o.id = t.order_tax_id"
            . ' WHERE ' . self::TAX_WHERE . ($source === null ? '' : ' AND o.order_source_id = :src') . ' GROUP BY 1',
            ['tz' => $this->clock->timezone()->getName(), 'from' => $start->format('Y-m-d H:i:s'), 'to' => $end->format('Y-m-d H:i:s')] + ($source === null ? [] : ['src' => $source])
        ) as $day => $amount) {
            $days[substr((string) $day, 0, 10)] = (float) $amount;
        }

        return $days;
    }

    // ------------------------------------------------------------------ aides

    private static function slice(array $days, string $from, string $to): array
    {
        return array_filter($days, fn ($day) => $day >= $from && $day <= $to, ARRAY_FILTER_USE_KEY);
    }

    /** Nom traduit d'un type de note de frais du référentiel (comme les budgets par poste) ; nom saisi par le site : tel quel */
    private function referential(string $name, string $locale): string
    {
        $key = 'referentiel.type_note_de_frais.' . $name;
        $translated = $this->translator->trans($key, [], 'gestion', $locale);

        return $translated === $key ? $name : $translated;
    }

    private function label(string $key, string $locale): string
    {
        return $this->translator->trans('statistics.sections.' . $key, [], 'gestion', $locale);
    }

    private function caveat(string $code, string $locale, array $params = []): array
    {
        return ['code' => $code, 'text' => $this->translator->trans("statistics.caveats.$code", $params, 'gestion', $locale)];
    }
}
