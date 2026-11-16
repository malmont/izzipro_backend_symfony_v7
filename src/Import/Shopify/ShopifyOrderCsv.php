<?php

namespace App\Import\Shopify;

/**
 * Lecteur du fichier d'export des COMMANDES de Shopify (lot IMP-3, 16/11/2026, `docs/import-boutique.md` § 10).
 *
 * L'export écrit UNE LIGNE PAR ARTICLE : toutes les lignes d'une commande portent son numéro (« Name », par exemple `#1001`) ; la
 * première porte en plus les champs de la commande (état de paiement, dates, devise, montants), les suivantes les laissent vides. Les
 * lignes d'une même commande ne se suivent pas forcément : elles sont regroupées par numéro.
 *
 * Ne sont lus que les champs dont l'archive a besoin : numéro, courriel (pour retrouver le compte, jamais gardé), état de paiement,
 * dates, devise, total, taxes, port, montant remboursé, date d'annulation, et pour chaque article son nom, sa quantité, son prix et
 * sa référence. Adresses, téléphone, notes et moyen de paiement ne sont pas lus du tout.
 *
 * Le fichier est en UTF-8, avec ou sans marque d'ordre des octets ; un autre encodage est refusé (`NOT_UTF8`).
 */
final class ShopifyOrderCsv
{
    /** Lignes d'articles lues au plus (une commande en a souvent plusieurs) */
    public const MAX_ROWS = 20000;
    /** en-tête du fichier (en minuscules) → clé interne */
    private const COLUMNS = [
        'name' => 'ref', 'email' => 'email', 'financial status' => 'financial', 'paid at' => 'paidAt', 'created at' => 'createdAt', 'cancelled at' => 'cancelledAt',
        'currency' => 'currency', 'subtotal' => 'subtotal', 'shipping' => 'shipping', 'taxes' => 'taxes', 'total' => 'total', 'refunded amount' => 'refunded',
        'lineitem quantity' => 'quantity', 'lineitem name' => 'itemName', 'lineitem price' => 'itemPrice', 'lineitem sku' => 'sku',
    ];
    public const NOT_AN_ORDER_FILE = 'NOT_AN_ORDER_FILE';
    public const TOO_MANY_ROWS = 'TOO_MANY_ROWS';
    public const EMPTY_FILE = 'EMPTY_FILE';
    public const NOT_UTF8 = 'NOT_UTF8';

    /**
     * @return array{error: ?string, rows: int, orders: list<array<string, mixed>>}
     *   commande : { line (sa première ligne), ref, email, financial, paidAt, createdAt, cancelledAt, currency, total, taxes, shipping,
     *   refunded, lines: list<{ line, name, quantity, price, sku }> } ; les textes sont rendus tels que le fichier les écrit (les
     *   contrôles et les conversions sont faits par le plan)
     */
    public function read(string $path): array
    {
        $none = static fn (string $error, int $rows = 0): array => ['error' => $error, 'rows' => $rows, 'orders' => []];
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return $none(self::EMPTY_FILE);
        }
        try {
            $first = fgets($handle);
            if ($first === false || trim($first) === '') {
                return $none(self::EMPTY_FILE);
            }
            $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
            $separator = ',';
            foreach ([';', "\t"] as $candidate) {
                if (substr_count($first, $candidate) > substr_count($first, $separator)) {
                    $separator = $candidate;
                }
            }
            $map = [];
            foreach (str_getcsv(rtrim($first, "\r\n"), $separator, '"', '') as $i => $name) {
                $key = self::COLUMNS[mb_strtolower(trim((string) $name))] ?? null;
                if ($key !== null && !isset($map[$key])) {
                    $map[$key] = $i;
                }
            }
            // un export de commandes porte le numéro, l'état de paiement, le total et le nom des articles ; un export de clients ou
            // de produits n'a pas ces quatre colonnes ensemble
            foreach (['ref', 'financial', 'total', 'itemName'] as $required) {
                if (!isset($map[$required])) {
                    return $none(self::NOT_AN_ORDER_FILE);
                }
            }
            $orders = [];
            $rows = 0;
            $line = 1; // l'en-tête
            while (($cells = fgetcsv($handle, null, $separator, '"', '')) !== false) {
                ++$line;
                if ($cells === [null] || $cells === []) {
                    continue;
                }
                foreach ($cells as $cell) {
                    if ($cell !== null && !mb_check_encoding((string) $cell, 'UTF-8')) {
                        return $none(self::NOT_UTF8, $rows); // un texte mal encodé serait refusé par la base au milieu de l'import
                    }
                }
                if (++$rows > self::MAX_ROWS) {
                    return $none(self::TOO_MANY_ROWS, $rows);
                }
                $get = static fn (string $key): string => isset($map[$key]) ? trim((string) ($cells[$map[$key]] ?? '')) : '';
                $ref = $get('ref');
                // une ligne sans numéro ne se rattache à aucune commande : elle est gardée sous une clé à elle, le plan la refusera
                $key = $ref !== '' ? $ref : "\0ligne" . $line;
                if (!isset($orders[$key])) {
                    $orders[$key] = ['line' => $line, 'ref' => $ref, 'email' => '', 'financial' => '', 'paidAt' => '', 'createdAt' => '', 'cancelledAt' => '', 'currency' => '',
                        'total' => '', 'taxes' => '', 'shipping' => '', 'refunded' => '', 'lines' => []];
                }
                // les champs de la commande sont sur sa première ligne ; une ligne suivante ne les efface pas
                foreach (['email', 'financial', 'paidAt', 'createdAt', 'cancelledAt', 'currency', 'total', 'taxes', 'shipping', 'refunded'] as $field) {
                    if ($orders[$key][$field] === '' && $get($field) !== '') {
                        $orders[$key][$field] = $get($field);
                    }
                }
                if ($get('itemName') !== '') {
                    $orders[$key]['lines'][] = ['line' => $line, 'name' => $get('itemName'), 'quantity' => $get('quantity'), 'price' => $get('itemPrice'), 'sku' => ltrim($get('sku'), "'")];
                }
            }

            return ['error' => $rows === 0 ? self::EMPTY_FILE : null, 'rows' => $rows, 'orders' => array_values($orders)];
        } finally {
            fclose($handle);
        }
    }
}
