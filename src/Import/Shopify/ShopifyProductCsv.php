<?php

namespace App\Import\Shopify;

/**
 * Lecteur du fichier d'export des produits d'une boutique Shopify (CSV), lot IMP-1. Il ne lit que le fichier : rien du site.
 *
 * Forme du fichier : une ligne par variante ; les lignes d'un même produit partagent la colonne « Handle » ; la première porte le
 * titre, la description (HTML, souvent sur plusieurs lignes entre guillemets), le type, les mots-clés et les noms des options ; les
 * suivantes ne portent que les valeurs d'options, le prix, le stock, le code-barres ; une ligne qui ne porte qu'une image (« Image
 * Src ») ajoute une image au produit. Séparateur : virgule (un point-virgule ou une tabulation, produits par un tableur, sont
 * reconnus à l'en-tête). Encodage : UTF-8, avec ou sans marque d'ordre des octets ; un autre encodage est refusé (`NOT_UTF8`).
 */
final class ShopifyProductCsv
{
    public const MAX_ROWS = 5000;
    /** en-tête du fichier (en minuscules) → clé interne */
    private const COLUMNS = [
        'handle' => 'handle', 'title' => 'title', 'body (html)' => 'body', 'vendor' => 'vendor', 'product category' => 'category', 'type' => 'type', 'tags' => 'tags',
        'published' => 'published', 'status' => 'status',
        'option1 name' => 'o1n', 'option1 value' => 'o1v', 'option2 name' => 'o2n', 'option2 value' => 'o2v', 'option3 name' => 'o3n', 'option3 value' => 'o3v',
        'variant sku' => 'sku', 'variant inventory qty' => 'qty', 'variant price' => 'price', 'variant compare at price' => 'compareAt', 'variant barcode' => 'barcode',
        'cost per item' => 'cost', 'image src' => 'image', 'image position' => 'imagePosition', 'variant image' => 'variantImage',
    ];
    public const NOT_A_PRODUCT_FILE = 'NOT_A_PRODUCT_FILE';
    public const TOO_MANY_ROWS = 'TOO_MANY_ROWS';
    public const EMPTY_FILE = 'EMPTY_FILE';
    public const NOT_UTF8 = 'NOT_UTF8';

    /**
     * @return array{error: ?string, rows: int, products: list<array<string, mixed>>}
     *   produit : { handle, line, title, body, vendor, type, category, tags, published: bool, status: string, optionNames: list<string>,
     *   variants: list<{line, options: list<string>, sku, priceRaw, compareAtRaw, costRaw, qtyRaw, barcode, image}>, images: list<string> }
     */
    public function read(string $path): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return ['error' => self::EMPTY_FILE, 'rows' => 0, 'products' => []];
        }
        try {
            $first = fgets($handle);
            if ($first === false || trim($first) === '') {
                return ['error' => self::EMPTY_FILE, 'rows' => 0, 'products' => []];
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
            if (!isset($map['handle'], $map['title'])) {
                return ['error' => self::NOT_A_PRODUCT_FILE, 'rows' => 0, 'products' => []];
            }
            $products = [];
            $rows = 0;
            $line = 1; // l'en-tête
            while (($cells = fgetcsv($handle, null, $separator, '"', '')) !== false) {
                ++$line;
                if ($cells === [null] || $cells === []) {
                    continue;
                }
                foreach ($cells as $cell) {
                    if ($cell !== null && !mb_check_encoding((string) $cell, 'UTF-8')) {
                        return ['error' => self::NOT_UTF8, 'rows' => $rows, 'products' => []]; // un texte mal encodé serait refusé par la base au milieu de l'import
                    }
                }
                if (++$rows > self::MAX_ROWS) {
                    return ['error' => self::TOO_MANY_ROWS, 'rows' => $rows, 'products' => []];
                }
                $get = static fn (string $key): string => isset($map[$key]) ? trim((string) ($cells[$map[$key]] ?? '')) : '';
                $key = mb_strtolower($get('handle'));
                if ($key === '') {
                    continue; // ligne sans produit : rien à en faire
                }
                if (!isset($products[$key])) {
                    $products[$key] = [
                        'handle' => $get('handle'), 'line' => $line, 'title' => $get('title'), 'body' => $get('body'), 'vendor' => $get('vendor'), 'type' => $get('type'),
                        'category' => $get('category'), 'tags' => $get('tags'), 'published' => !in_array(mb_strtolower($get('published')), ['false', '0', 'no', 'non'], true),
                        'status' => mb_strtolower($get('status')) ?: 'active', 'optionNames' => [$get('o1n'), $get('o2n'), $get('o3n')], // par position : une option vide ne décale pas les suivantes
                        'variants' => [], 'images' => [],
                    ];
                } elseif ($products[$key]['title'] === '' && $get('title') !== '') {
                    $products[$key]['title'] = $get('title'); // lignes dans le désordre : le titre arrive après
                }
                $image = $get('image');
                if ($image !== '' && !in_array($image, $products[$key]['images'], true)) {
                    $products[$key]['images'][] = $image;
                }
                // une ligne est une variante si elle porte un prix, une valeur d'option, une référence ou un code ; sinon c'est une ligne d'image
                if ($get('price') !== '' || $get('o1v') !== '' || $get('sku') !== '' || $get('barcode') !== '') {
                    $products[$key]['variants'][] = [
                        'line' => $line, 'options' => [$get('o1v'), $get('o2v'), $get('o3v')], 'sku' => $get('sku'), 'priceRaw' => $get('price'), 'compareAtRaw' => $get('compareAt'),
                        'costRaw' => $get('cost'), 'qtyRaw' => $get('qty'), 'barcode' => $get('barcode'), 'image' => $get('variantImage'),
                    ];
                }
            }

            return ['error' => $rows === 0 ? self::EMPTY_FILE : null, 'rows' => $rows, 'products' => array_values($products)];
        } finally {
            fclose($handle);
        }
    }

    /** « 19.99 », « 19,99 », « 1 299,00 », « 1,299.00 » → cents ; null si ce n'est pas un montant positif ou nul */
    public static function cents(string $raw): ?int
    {
        $s = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $raw) ?? '';
        if ($s === '' || !preg_match('/^\d[\d.,]*$/', $s)) {
            return null;
        }
        $lastDot = strrpos($s, '.');
        $lastComma = strrpos($s, ',');
        $decimal = $lastDot === false ? $lastComma : ($lastComma === false ? $lastDot : max($lastDot, $lastComma));
        if ($decimal !== false && strlen($s) - $decimal - 1 === 3 && substr_count($s, $s[$decimal]) >= 1 && ($lastDot === false || $lastComma === false)) {
            $decimal = false; // « 1,299 » ou « 1.299 » : séparateur de milliers, pas de décimales
        }
        $whole = $decimal === false ? $s : substr($s, 0, $decimal);
        $fraction = $decimal === false ? '' : substr($s, $decimal + 1);
        $whole = str_replace(['.', ','], '', $whole);
        if ($whole === '' || !ctype_digit($whole) || ($fraction !== '' && !ctype_digit($fraction)) || strlen($whole) > 9) {
            return null;
        }
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

        return (int) $whole * 100 + (int) $fraction;
    }
}
