<?php

namespace App\Services\BarcodeService;

use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Ce qui peut porter une étiquette dans le catalogue (lot CB-2, `docs/codes-barres.md`) : une ligne par variante, et une ligne par
 * article sans variante. Lu par l'écran « Gestion des Codes-Barres » d'EasyAdmin, page par page (plus de chargement de tout le
 * catalogue), en SQL : rien n'est hydraté.
 *
 * Code d'une ligne : celui de la variante ; à défaut celui de son article quand elle est sa seule variante ; celui de l'article pour un
 * article sans variante. Prix : celui de la variante, sinon de l'article (prix du catalogue, hors promotion).
 */
class BarcodeCatalogList
{
    public const PER_PAGE = 50;

    private const ROWS = "SELECT 'variant' AS type, v.id AS id, p.id AS product_id, p.name AS name, p.code AS code, c.name AS color, s.name AS size, v.barcode AS own_barcode,"
        . ' COALESCE(v.barcode, CASE WHEN (SELECT COUNT(*) FROM product_variant x WHERE x.product_id = p.id) = 1 THEN p.barcode END) AS barcode,'
        . ' COALESCE(v.price, ROUND(p.price)::int) AS price_cents'
        . ' FROM product_variant v JOIN product p ON p.id = v.product_id LEFT JOIN color c ON c.id = v.color_id LEFT JOIN size s ON s.id = v.size_id'
        . " UNION ALL SELECT 'product' AS type, p.id AS id, p.id AS product_id, p.name AS name, p.code AS code, NULL AS color, NULL AS size, p.barcode AS own_barcode,"
        . ' p.barcode AS barcode, ROUND(p.price)::int AS price_cents'
        . ' FROM product p WHERE NOT EXISTS (SELECT 1 FROM product_variant v WHERE v.product_id = p.id)';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /**
     * Une page de la liste. `$search` : nom, code d'article, code-barres (forme canonique) ou identifiant d'article ; `$missingOnly` :
     * seulement ce qui n'a pas de code.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function page(string $search, bool $missingOnly, int $page): array
    {
        $search = trim($search);
        $page = max(1, $page);
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(r.name ILIKE :like OR r.code ILIKE :like OR r.barcode = :barcode OR r.own_barcode = :barcode' . (ctype_digit($search) && strlen($search) < 10 ? ' OR r.product_id = :id' : '') . ')';
            $params['like'] = '%' . addcslashes($search, '%_\\') . '%';
            $params['barcode'] = (string) CatalogBarcode::normalize($search);
            if (ctype_digit($search) && strlen($search) < 10) {
                $params['id'] = (int) $search;
            }
        }
        if ($missingOnly) {
            $where[] = 'r.barcode IS NULL';
        }
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT r.*, COUNT(*) OVER () AS total FROM (' . self::ROWS . ') r' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . sprintf(' ORDER BY LOWER(r.name), r.product_id, r.type DESC, r.id LIMIT %d OFFSET %d', self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            $params
        );
        $total = $rows === [] ? 0 : (int) $rows[0]['total'];

        return ['rows' => array_map(self::row(...), $rows), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / self::PER_PAGE))];
    }

    /**
     * Les lignes de ces clés (`variant-412`, `product-95`), par clé ; une clé inconnue est absente.
     *
     * @param list<string> $keys
     *
     * @return array<string, array<string, mixed>>
     */
    public function rowsFor(array $keys): array
    {
        $ids = ['variant' => [], 'product' => []];
        foreach ($keys as $key) {
            if (preg_match('/\A(variant|product)-(\d{1,9})\z/', (string) $key, $m) === 1) {
                $ids[$m[1]][] = (int) $m[2];
            }
        }
        if ($ids['variant'] === [] && $ids['product'] === []) {
            return [];
        }
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT r.* FROM (' . self::ROWS . ") r WHERE (r.type = 'variant' AND r.id IN (:variants)) OR (r.type = 'product' AND r.id IN (:products))",
            ['variants' => $ids['variant'] ?: [0], 'products' => $ids['product'] ?: [0]],
            ['variants' => ArrayParameterType::INTEGER, 'products' => ArrayParameterType::INTEGER]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row['type'] . '-' . $row['id']] = self::row($row);
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private static function row(array $row): array
    {
        $subtitle = implode(' / ', array_filter([trim((string) $row['color']), trim((string) $row['size'])], static fn (string $p): bool => $p !== ''));
        $barcode = $row['barcode'] !== null ? (string) $row['barcode'] : null;

        return [
            'key' => $row['type'] . '-' . $row['id'], 'type' => (string) $row['type'], 'id' => (int) $row['id'], 'productId' => (int) $row['product_id'],
            'title' => (string) $row['name'], 'subtitle' => $subtitle !== '' ? $subtitle : null, 'code' => $row['code'],
            'barcode' => $barcode, 'barcodeOf' => $barcode === null ? null : ($row['type'] === 'variant' && $row['own_barcode'] === null ? 'product' : (string) $row['type']),
            'symbology' => $barcode !== null ? BarcodeLabelPrinter::symbology($barcode) : null,
            'priceCents' => (int) ($row['price_cents'] ?? 0) > 0 ? (int) $row['price_cents'] : null, // prix à zéro : « pas de prix », comme les étiquettes de la gestion
        ];
    }
}
