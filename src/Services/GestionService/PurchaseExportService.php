<?php

namespace App\Services\GestionService;

use App\Services\AccountingService\AccountingCsv;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;

/**
 * Export comptable des achats (lot G5, 10/11/2026, `docs/gestion-mise-a-niveau.md` § 3) : une ligne par produit de commande
 * fournisseur, par frais de port et par note de frais de la période (dates civiles de la commande ou de la note, bornes
 * inclusives, jours du magasin par défaut : mois courant), en trois requêtes SQL. CSV « ; », BOM UTF-8, virgule décimale
 * (mêmes conventions que la synthèse comptable de la caisse, `AccountingCsv`). Montants en unités monétaires dans le CSV
 * (cents ÷ 100), devise du site en en-tête de colonne.
 */
class PurchaseExportService
{
    public const TYPE_PRODUCT = 'Produit';
    public const TYPE_SHIPPING = 'Frais de port';
    public const TYPE_EXPENSE = 'Note de frais';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly StoreClock $clock, private readonly \App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency)
    {
    }

    /**
     * @return array{from: string, to: string, currency: string, rows: list<array<string, mixed>>, totals: array{products: int, shipping: int, expenses: int, total: int}}
     */
    public function build(?string $from, ?string $to, ?int $collectionId): array
    {
        [$fromDay, $toDay] = $this->period($from, $to);
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $params = ['from' => $fromDay, 'to' => $toDay];
        $collectionWhere = '';
        if ($collectionId !== null) {
            $collectionWhere = ' AND col.id = :collection';
            $params['collection'] = $collectionId;
        }
        $rows = [];
        $totals = ['products' => 0, 'shipping' => 0, 'expenses' => 0];

        $products = $conn->fetchAllAssociative(
            'SELECT col.nom_collection AS collection, c.name AS commande, c.date AS day, f.name AS fournisseur, p.name AS label, COALESCE(p.quantity, 0) AS quantity,' .
            ' COALESCE(p.commande_cost_cents, p.purchase_price, p.price, 0) * COALESCE(c.exchange_rate, 1) AS unit_cost, p.coefficient_multiplier AS coefficient, c.currency AS purchase_currency, c.exchange_rate AS rate' .
            ' FROM product p JOIN commande c ON c.id = p.commande_id JOIN collections col ON col.id = c.collections_id LEFT JOIN fournisseur f ON f.id = c.fournisseur_id' .
            ' WHERE c.date >= :from AND c.date < (CAST(:to AS date) + 1)' . $collectionWhere . ' ORDER BY c.date, c.id, p.id',
            $params
        );
        foreach ($products as $r) {
            $unit = (int) round((float) $r['unit_cost']);
            $qty = (int) $r['quantity'];
            $totals['products'] += $unit * $qty;
            $rows[] = self::row(self::TYPE_PRODUCT, $r['collection'], $r['commande'], $r['day'], $r['fournisseur'], $r['label'], $qty, $unit, $unit * $qty, $r['coefficient'], null, $r['purchase_currency'], $r['rate']);
        }
        $shipping = $conn->fetchAllAssociative(
            'SELECT col.nom_collection AS collection, c.name AS commande, c.date AS day, f.name AS fournisseur, s.name AS label, s.price * COALESCE(c.exchange_rate, 1) AS amount, t.name AS carrier, c.currency AS purchase_currency, c.exchange_rate AS rate' .
            ' FROM frais_de_port s JOIN commande c ON c.id = s.commande_id JOIN collections col ON col.id = c.collections_id LEFT JOIN fournisseur f ON f.id = c.fournisseur_id LEFT JOIN transporteur t ON t.id = s.transporteur_id' .
            ' WHERE c.date >= :from AND c.date < (CAST(:to AS date) + 1)' . $collectionWhere . ' ORDER BY c.date, c.id',
            $params
        );
        foreach ($shipping as $r) {
            $amount = (int) round((float) $r['amount']);
            $totals['shipping'] += $amount;
            $rows[] = self::row(self::TYPE_SHIPPING, $r['collection'], $r['commande'], $r['day'], $r['fournisseur'], $r['label'], 1, $amount, $amount, null, $r['carrier'], $r['purchase_currency'], $r['rate']);
        }
        $expenses = $conn->fetchAllAssociative(
            'SELECT col.nom_collection AS collection, n.date AS day, COALESCE(n.description, n.name) AS label, n.montant AS amount, tn.name AS type' .
            ' FROM note_de_frais n JOIN collections col ON col.id = n.collection_id LEFT JOIN type_note_de_frais tn ON tn.id = n.type_note_de_frais_id' .
            ' WHERE n.date >= :from AND n.date <= :to' . $collectionWhere . ' ORDER BY n.date, n.id',
            $params
        );
        foreach ($expenses as $r) {
            $amount = (int) $r['amount'];
            $totals['expenses'] += $amount;
            $rows[] = self::row(self::TYPE_EXPENSE, $r['collection'], null, $r['day'], null, $r['label'], 1, $amount, $amount, null, $r['type']);
        }
        $totals['total'] = $totals['products'] + $totals['shipping'] + $totals['expenses'];

        return ['from' => $fromDay, 'to' => $toDay, 'currency' => strtoupper($this->currency->code()), 'rows' => $rows, 'totals' => $totals];
    }

    /** @param array<string, mixed> $export */
    public function csv(array $export): string
    {
        $cur = $export['currency'];
        $header = ['Type', 'Collection', 'Commande', 'Date', 'Fournisseur', 'Libellé', 'Quantité', "Coût unitaire ($cur)", "Total ($cur)", 'Coefficient', 'Transporteur / Type de note', 'Devise d\'achat', 'Taux'];
        $lines = [];
        foreach ($export['rows'] as $r) {
            $lines[] = [$r['type'], $r['collection'], $r['commande'], $r['date'], $r['fournisseur'], $r['label'], $r['quantity'], self::money($r['unitCost']), self::money($r['total']), $r['coefficient'] === null ? '' : str_replace('.', ',', (string) $r['coefficient']), $r['detail'], $r['purchaseCurrency'] ?? '', isset($r['exchangeRate']) ? str_replace('.', ',', (string) $r['exchangeRate']) : ''];
        }
        $t = $export['totals'];
        $lines[] = ['Total produits', '', '', '', '', '', '', '', self::money($t['products']), '', '', '', ''];
        $lines[] = ['Total frais de port', '', '', '', '', '', '', '', self::money($t['shipping']), '', '', '', ''];
        $lines[] = ['Total notes de frais', '', '', '', '', '', '', '', self::money($t['expenses']), '', '', '', ''];
        $lines[] = ['Total achats', '', '', '', '', '', '', '', self::money($t['total']), '', '', '', ''];

        return AccountingCsv::write($header, $lines);
    }

    /** @return array{0: string, 1: string} bornes Y-m-d inclusives ; défaut : mois courant du magasin ; 422 si invalides */
    private function period(?string $from, ?string $to): array
    {
        $tz = $this->clock->timezone();
        $now = new \DateTimeImmutable('now', $tz);
        $errors = [];
        foreach (['from' => $from, 'to' => $to] as $name => $value) {
            if ($value !== null && $value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $errors[$name] = 'INVALID_DATE';
            }
        }
        if ($errors) {
            throw new GestionValidationException($errors, 'Dates attendues au format AAAA-MM-JJ');
        }
        $fromDay = $from ?: $now->format('Y-m-01');
        $toDay = $to ?: $now->format('Y-m-t');
        if ($toDay < $fromDay) {
            throw new GestionValidationException(['to' => 'BEFORE_FROM'], 'to : antérieur à from');
        }

        return [$fromDay, $toDay];
    }

    /** @return array<string, mixed> */
    private static function row(string $type, ?string $collection, ?string $commande, mixed $day, ?string $fournisseur, ?string $label, int $quantity, int $unitCost, int $total, mixed $coefficient, ?string $detail, ?string $purchaseCurrency = null, mixed $rate = null): array
    {
        return [
            'type' => $type, 'collection' => $collection, 'commande' => $commande, 'date' => substr((string) $day, 0, 10), 'fournisseur' => $fournisseur, 'label' => $label,
            'quantity' => $quantity, 'unitCost' => $unitCost, 'total' => $total, 'coefficient' => $coefficient === null ? null : round((float) $coefficient, 2), 'detail' => $detail,
            'purchaseCurrency' => $purchaseCurrency, 'exchangeRate' => $rate === null ? null : (float) $rate, // devise d'achat et taux de la commande (lot G6), montants déjà convertis
        ];
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '');
    }
}
