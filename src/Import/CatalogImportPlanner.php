<?php

namespace App\Import;

use App\Import\Shopify\ShopifyProductCsv;
use App\Services\BarcodeService\CatalogBarcodeException;
use App\Services\BarcodeService\CatalogBarcodeService;

/**
 * Lot IMP-1 : ce qu'un fichier de catalogue donnerait sur le site courant, SANS RIEN ÉCRIRE. Le même plan sert au rapport d'analyse
 * (ce que le gérant confirme) et à l'exécution (ce qui est écrit) : l'un ne peut pas dire autre chose que ce que l'autre fait.
 *
 * Par produit : importable, refusé (avec sa raison) ou déjà importé par un import précédent. Par variante : prix en cents, stock de
 * départ, taille, couleur, autres options, code-barres gardé ou écarté (forme refusée, forme réservée à la caisse, en double dans le
 * fichier, déjà porté par une fiche du site : règle de CB-1). Par image : gardée si elle vient d'un serveur d'images de la source.
 */
class CatalogImportPlanner
{
    public const MAX_VARIANTS = 100;
    /** Au-delà, la quantité du fichier est ramenée à ce plafond (un entier de la base a ses limites) */
    public const MAX_STOCK = 1_000_000;
    public const TITLE_MISSING = 'TITLE_MISSING';
    public const PRICE_INVALID = 'PRICE_INVALID';
    public const NO_VARIANT = 'NO_VARIANT';
    public const VARIANTS_TOO_MANY = 'VARIANTS_TOO_MANY';
    public const BARCODE_DUPLICATE_IN_FILE = 'BARCODE_DUPLICATE_IN_FILE';
    public const IMAGE_HOST_REFUSED = 'IMAGE_HOST_REFUSED';
    public const COMPARE_AT_IGNORED = 'COMPARE_AT_IGNORED';
    private const SIZE_NAMES = ['size', 'taille', 'pointure'];
    private const COLOR_NAMES = ['color', 'colour', 'couleur'];

    public function __construct(private readonly ImportJobStore $store, private readonly CatalogBarcodeService $barcodes)
    {
    }

    /**
     * @param list<array<string, mixed>> $products produits lus par `ShopifyProductCsv::read`
     * @return array{items: list<array<string, mixed>>, issues: list<array{line: int, handle: string, code: string, detail: string}>}
     */
    public function plan(string $source, array $products): array
    {
        // Codes-barres du fichier : un code porté par deux lignes n'est gardé par aucune (c'est au gérant de dire laquelle a raison)
        $seen = [];
        foreach ($products as $product) {
            foreach ($product['variants'] as $variant) {
                $code = $this->barcodes->normalize($variant['barcode'] !== '' ? $variant['barcode'] : null);
                if ($code !== null) {
                    $seen[$code] = ($seen[$code] ?? 0) + 1;
                }
            }
        }
        $items = [];
        $issues = [];
        foreach ($products as $product) {
            $handle = (string) $product['handle'];
            $item = ['ref' => mb_strtolower($handle), 'handle' => $handle, 'line' => (int) $product['line'], 'status' => 'ok', 'reason' => null, 'name' => mb_substr(trim((string) $product['title']), 0, 255),
                'body' => (string) $product['body'], 'tags' => mb_substr(trim((string) $product['tags']), 0, 500), 'category' => $this->categoryName($product),
                'visible' => (bool) $product['published'] && $product['status'] === 'active', 'variants' => [], 'images' => [], 'productId' => null];
            $refuse = function (string $code, int $line, string $detail = '') use (&$item, &$issues, $handle): void {
                if ($item['status'] === 'ok') {
                    $item['status'] = 'refused';
                    $item['reason'] = $code;
                }
                $issues[] = ['line' => $line, 'handle' => $handle, 'code' => $code, 'detail' => $detail];
            };
            $existing = $this->store->productOf($source, $item['ref']);
            if ($existing !== null) {
                $item['status'] = 'already';
                $item['productId'] = $existing;
                $items[] = $item;
                continue;
            }
            if ($item['name'] === '') {
                $refuse(self::TITLE_MISSING, $item['line']);
            }
            if ($product['variants'] === []) {
                $refuse(self::NO_VARIANT, $item['line']);
            } elseif (count($product['variants']) > self::MAX_VARIANTS) {
                $refuse(self::VARIANTS_TOO_MANY, $item['line'], (string) count($product['variants']));
            }
            $names = array_map('mb_strtolower', $product['optionNames']);
            foreach ($product['variants'] as $index => $raw) {
                $price = ShopifyProductCsv::cents((string) $raw['priceRaw']);
                if ($price === null || $price <= 0 || $price > 100_000_000) {
                    $refuse(self::PRICE_INVALID, (int) $raw['line'], (string) $raw['priceRaw']);
                    continue;
                }
                $variant = ['ref' => $item['ref'] . '#' . $index, 'line' => (int) $raw['line'], 'priceCents' => $price, 'costCents' => ShopifyProductCsv::cents((string) $raw['costRaw']),
                    'stock' => (int) max(0, min(self::MAX_STOCK, is_numeric($raw['qtyRaw']) ? (float) $raw['qtyRaw'] : 0)), 'sku' => mb_substr((string) $raw['sku'], 0, 60), 'size' => null, 'color' => null, 'others' => [], 'barcode' => null];
                $compareAt = ShopifyProductCsv::cents((string) $raw['compareAtRaw']);
                if ($compareAt !== null && $compareAt > $price) {
                    $issues[] = ['line' => (int) $raw['line'], 'handle' => $handle, 'code' => self::COMPARE_AT_IGNORED, 'detail' => (string) $raw['compareAtRaw']];
                }
                foreach ($raw['options'] as $position => $value) {
                    $value = mb_substr(trim((string) $value), 0, 60);
                    $name = $names[$position] ?? '';
                    if ($value === '' || $name === '' || ($name === 'title' && mb_strtolower($value) === 'default title')) {
                        continue; // « Title / Default Title » : la façon dont la source dit « pas d'option »
                    }
                    if (in_array($name, self::SIZE_NAMES, true) && $variant['size'] === null) {
                        $variant['size'] = $value;
                    } elseif (in_array($name, self::COLOR_NAMES, true) && $variant['color'] === null) {
                        $variant['color'] = $value;
                    } else {
                        $variant['others'][] = ['name' => mb_substr((string) $product['optionNames'][$position], 0, 60), 'value' => $value];
                    }
                }
                if ($raw['barcode'] !== '') {
                    try {
                        $code = $this->barcodes->validate($raw['barcode']);
                        if ($code !== null && ($seen[$code] ?? 0) > 1) {
                            $issues[] = ['line' => (int) $raw['line'], 'handle' => $handle, 'code' => self::BARCODE_DUPLICATE_IN_FILE, 'detail' => $code];
                        } elseif ($code !== null && $this->barcodes->holder($code) !== null) {
                            $issues[] = ['line' => (int) $raw['line'], 'handle' => $handle, 'code' => CatalogBarcodeService::ALREADY_USED, 'detail' => $code];
                        } else {
                            $variant['barcode'] = $code;
                        }
                    } catch (CatalogBarcodeException $e) {
                        $issues[] = ['line' => (int) $raw['line'], 'handle' => $handle, 'code' => $e->errorCode, 'detail' => mb_substr((string) $raw['barcode'], 0, 80)];
                    }
                }
                $item['variants'][] = $variant;
            }
            foreach ($product['images'] as $url) {
                // lot G-4 : une copie entre boutiques du groupe ne porte que des photos locales, recopiées ; un fichier, jamais les deux
                if ($source === \App\Import\ImportJobStore::SOURCE_GROUP ? ImportImageFetcher::allowedLocal((string) $url) : ImportImageFetcher::allowed((string) $url)) {
                    $item['images'][] = (string) $url;
                } else {
                    $issues[] = ['line' => $item['line'], 'handle' => $handle, 'code' => self::IMAGE_HOST_REFUSED, 'detail' => mb_substr((string) $url, 0, 120)];
                }
            }
            $items[] = $item;
        }

        return ['items' => $items, 'issues' => $issues];
    }

    /**
     * Le rapport d'analyse, rendu au gérant avant qu'il confirme.
     *
     * @param array{items: list<array<string, mixed>>, issues: list<array<string, mixed>>} $plan
     * @param list<string> $existingCategories noms des catégories du site, en minuscules
     * @return array<string, mixed>
     */
    public function report(array $plan, array $existingCategories): array
    {
        $ok = array_values(array_filter($plan['items'], static fn (array $i): bool => $i['status'] === 'ok'));
        $categories = [];
        foreach ($ok as $item) {
            if ($item['category'] !== null && !in_array(mb_strtolower($item['category']), $existingCategories, true)) {
                $categories[mb_strtolower($item['category'])] = $item['category'];
            }
        }
        $codes = array_count_values(array_column($plan['issues'], 'code'));
        $barcodeIssues = ['duplicateInFile' => $codes[self::BARCODE_DUPLICATE_IN_FILE] ?? 0, 'alreadyUsed' => $codes[CatalogBarcodeService::ALREADY_USED] ?? 0,
            'invalid' => ($codes[\App\Services\BarcodeService\CatalogBarcode::INVALID] ?? 0) + ($codes[\App\Services\BarcodeService\CatalogBarcode::RESERVED] ?? 0)];

        return [
            'products' => ['total' => count($plan['items']), 'importable' => count($ok), 'refused' => count(array_filter($plan['items'], static fn (array $i): bool => $i['status'] === 'refused')),
                'alreadyImported' => count(array_filter($plan['items'], static fn (array $i): bool => $i['status'] === 'already'))],
            'variants' => array_sum(array_map(static fn (array $i): int => count($i['variants']), $ok)),
            'stockUnits' => array_sum(array_map(static fn (array $i): int => array_sum(array_column($i['variants'], 'stock')), $ok)),
            'categoriesToCreate' => array_values($categories),
            'images' => ['total' => array_sum(array_map(static fn (array $i): int => count($i['images']), $ok)), 'refusedHost' => $codes[self::IMAGE_HOST_REFUSED] ?? 0],
            'barcodes' => ['kept' => count(array_filter(array_merge(...array_map(static fn (array $i): array => array_column($i['variants'], 'barcode'), $ok ?: [['variants' => []]])))), 'dropped' => $barcodeIssues],
            'compareAtIgnored' => $codes[self::COMPARE_AT_IGNORED] ?? 0,
            'issues' => $plan['issues'],
        ];
    }

    /** @param array<string, mixed> $product */
    private function categoryName(array $product): ?string
    {
        $type = trim((string) $product['type']);
        if ($type === '') { // « Vêtements > Hauts > T-shirts » : le dernier niveau
            $parts = array_values(array_filter(array_map('trim', explode('>', (string) $product['category'])), static fn (string $p): bool => $p !== ''));
            $type = $parts === [] ? '' : (string) end($parts);
        }

        return $type === '' ? null : mb_substr($type, 0, 255);
    }
}
