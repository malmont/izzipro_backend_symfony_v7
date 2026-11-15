<?php

namespace App\Services\BarcodeService;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Codes-barres du catalogue (lot CB-1, 15/11/2026, `docs/codes-barres.md`) : LE passage de tout chemin qui écrit un code d'article ou de
 * variante (fiches d'EasyAdmin, bouton « Générer », création d'une fiche par l'IA, « Générer les codes manquants »).
 *
 * La règle : sur un site, un code désigne UNE seule chose, un article ou une variante (jamais deux articles, deux variantes, une variante
 * et un article, même le sien). La base la garantit seule (déclencheur `catalog_barcode_guard`, un index unique par table) ; ce service
 * la fait respecter AVANT d'écrire, pour rendre un refus clair au lieu d'une erreur de la base, et tient ce que la base ne sait pas :
 * les caractères permis et les formes réservées à la caisse.
 *
 * - Un code saisi n'est jamais écrasé ni renuméroté : la génération ne touche que ce qui n'a pas de code (`BARCODE_ALREADY_SET` sinon),
 *   relu en base sous verrou de ligne, pour que deux demandes simultanées ne se recouvrent pas.
 * - Le code généré va à la variante ; à l'article seulement s'il n'a aucune variante (`BARCODE_HAS_VARIANTS` sinon).
 * - La génération se fait à la demande, jamais d'office.
 */
class CatalogBarcodeService
{
    public const ALREADY_USED = 'BARCODE_ALREADY_USED';
    public const ALREADY_SET = 'BARCODE_ALREADY_SET';
    public const HAS_VARIANTS = 'BARCODE_HAS_VARIANTS';

    /** Plafond d'un lancement de « Générer les codes manquants » : on relance pour la suite */
    public const BULK_LIMIT = 500;
    public const AUDIT_RESOURCE = 'catalog_barcode';

    private const SEQUENCE = 'catalog_barcode_seq';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ContentAuditRecorder $audit,
    ) {
    }

    /** Forme canonique (espaces autour retirés, vide → null, majuscules) : celle qu'on écrit et celle qu'on cherche */
    public function normalize(?string $raw): ?string
    {
        return CatalogBarcode::normalize($raw);
    }

    /**
     * Code saisi, prêt à être écrit sur `$for` : mis en forme, de forme permise, non réservé et libre. Rend la forme canonique, ou null
     * pour « pas de code ». `$for` est l'article ou la variante qui va le porter (son propre code ne le gêne pas) ; null pour une création.
     *
     * @throws CatalogBarcodeException `BARCODE_INVALID`, `BARCODE_RESERVED` ou `BARCODE_ALREADY_USED`
     */
    public function check(?string $raw, Product|ProductVariant|null $for = null): ?string
    {
        $code = $this->validate($raw);
        if ($code !== null) {
            $this->assertFree($code, $for);
        }

        return $code;
    }

    /**
     * Forme seule (sans lecture de la base) : rend la forme canonique ou null.
     *
     * @throws CatalogBarcodeException `BARCODE_INVALID` ou `BARCODE_RESERVED`
     */
    public function validate(?string $raw): ?string
    {
        $code = CatalogBarcode::normalize($raw);
        if ($code === null) {
            return null;
        }
        $refusal = CatalogBarcode::refusal($code);
        if ($refusal === CatalogBarcode::INVALID) {
            throw new CatalogBarcodeException($refusal, 'Code-barres refusé : 1 à 64 caractères, lettres sans accent, chiffres et - . _ / seulement, sans espace.');
        }
        if ($refusal === CatalogBarcode::RESERVED) {
            throw new CatalogBarcodeException($refusal, 'Ce code-barres a la forme d\'un document de la caisse (ticket, avoir, carte-cadeau, fiche client) ou d\'un coupon de fabricant : il ne peut pas désigner un article.');
        }

        return $code;
    }

    /** Avertissement à montrer à la saisie d'un code accepté, ou null : 13 chiffres dont la clé de contrôle est fausse */
    public function warning(?string $code): ?string
    {
        return $code !== null && CatalogBarcode::hasWrongCheckDigit($code)
            ? 'Ce code de 13 chiffres n\'a pas une clé de contrôle d\'EAN-13 juste : il est accepté et sera imprimé en Code 128.'
            : null;
    }

    /**
     * Ce qui porte le code (forme canonique) sur le site, hors `$except` ; null si le code est libre.
     *
     * @return array{type: string, id: int, productId: int, label: string}|null
     */
    public function holder(string $code, Product|ProductVariant|null $except = null): ?array
    {
        $rows = $this->connection()->fetchAllAssociative(
            "SELECT 'product' AS type, id, id AS product_id FROM product WHERE barcode = :code"
            . " UNION ALL SELECT 'variant' AS type, id, product_id FROM product_variant WHERE barcode = :code ORDER BY type, id",
            ['code' => $code]
        );
        $exceptType = $except instanceof Product ? 'product' : ($except instanceof ProductVariant ? 'variant' : null);
        $exceptId = $except?->getId();
        foreach ($rows as $row) {
            if ($exceptId !== null && $row['type'] === $exceptType && (int) $row['id'] === $exceptId) {
                continue;
            }

            return ['type' => (string) $row['type'], 'id' => (int) $row['id'], 'productId' => (int) $row['product_id'], 'label' => $this->label((string) $row['type'], (int) $row['id'])];
        }

        return null;
    }

    /** @throws CatalogBarcodeException `BARCODE_ALREADY_USED`, avec ce qui porte déjà le code */
    public function assertFree(string $code, Product|ProductVariant|null $for = null): void
    {
        $holder = $this->holder($code, $for);
        if ($holder !== null) {
            throw new CatalogBarcodeException(self::ALREADY_USED, sprintf('Ce code-barres est déjà celui de « %s ».', $holder['label']), $holder);
        }
    }

    /** Un code généré libre (EAN-13 interne, préfixe 29) ; rien n'est écrit, le numéro tiré n'est jamais redonné */
    public function generate(): string
    {
        return $this->generateMany(1)[0];
    }

    /**
     * Donne un code généré à une variante, ou à un article sans variante, qui n'en a pas ; l'écrit et le rend.
     *
     * @throws CatalogBarcodeException `BARCODE_ALREADY_SET` (un code n'est jamais écrasé), `BARCODE_HAS_VARIANTS`
     */
    public function generateFor(Product|ProductVariant $target): string
    {
        $id = $target->getId();
        if ($id === null) {
            throw new \LogicException('Code-barres : la fiche doit être enregistrée avant de recevoir un code généré');
        }
        $em = $this->em();
        $conn = $em->getConnection();
        $table = $target instanceof Product ? 'product' : 'product_variant';
        $conn->beginTransaction();
        try {
            // La valeur de la BASE, sous verrou de ligne : deux demandes simultanées ne se recouvrent pas, et un code saisi entre-temps reste
            $current = $conn->fetchOne(sprintf('SELECT barcode FROM %s WHERE id = ? FOR UPDATE', $table), [$id]);
            if ($current === false) {
                throw new \LogicException('Code-barres : fiche introuvable');
            }
            if (CatalogBarcode::normalize($current) !== null || CatalogBarcode::normalize($target->getBarcode()) !== null) {
                throw new CatalogBarcodeException(self::ALREADY_SET, 'Cette fiche porte déjà un code-barres : un code saisi n\'est jamais remplacé par un code généré.');
            }
            if ($target instanceof Product && $conn->fetchOne('SELECT 1 FROM product_variant WHERE product_id = ? LIMIT 1', [$id]) !== false) {
                throw new CatalogBarcodeException(self::HAS_VARIANTS, 'Cet article a des variantes : le code généré se pose sur chaque variante, pas sur l\'article.');
            }
            $code = $this->generate();
            $target->setBarcode($code);
            $em->flush();
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }

        return $code;
    }

    /**
     * Ce que « Générer les codes manquants » écrirait sur tout le catalogue : les variantes sans code et les articles sans variante ni code.
     *
     * @return array{variants: int, products: int, total: int}
     */
    public function missingCount(): array
    {
        $conn = $this->connection();
        $variants = (int) $conn->fetchOne('SELECT COUNT(*) FROM product_variant WHERE barcode IS NULL');
        $products = (int) $conn->fetchOne('SELECT COUNT(*) FROM product p WHERE p.barcode IS NULL AND NOT EXISTS (SELECT 1 FROM product_variant v WHERE v.product_id = p.id)');

        return ['variants' => $variants, 'products' => $products, 'total' => $variants + $products];
    }

    /**
     * « Générer les codes manquants » : donne un code généré à ce qui n'en a pas (variantes d'abord, puis articles sans variante), au plus
     * `$limit` par lancement, sans jamais toucher un code présent. Une ligne au journal du site par lancement (qui, quand, combien).
     *
     * @return array{written: int, variants: int, products: int, remaining: int}
     */
    public function generateMissing(int $limit = self::BULK_LIMIT): array
    {
        $limit = max(1, min(self::BULK_LIMIT, $limit));
        $em = $this->em();
        $conn = $em->getConnection();
        $conn->beginTransaction();
        try {
            // Lignes verrouillées par leur identifiant (un second lancement simultané attend, puis ne voit plus que ce qui reste sans code),
            // puis relues : l'objet déjà en mémoire reprend la valeur de la base. Le verrou se prend en SQL, pas par la requête des
            // entités : celle des articles joint la table des véhicules (héritage), ce que « FOR UPDATE » refuse.
            $variantIds = $conn->fetchFirstColumn(sprintf('SELECT id FROM product_variant WHERE barcode IS NULL ORDER BY id LIMIT %d FOR UPDATE', $limit));
            $productIds = count($variantIds) >= $limit ? [] : $conn->fetchFirstColumn(sprintf(
                'SELECT p.id FROM product p WHERE p.barcode IS NULL AND NOT EXISTS (SELECT 1 FROM product_variant v WHERE v.product_id = p.id) ORDER BY p.id LIMIT %d FOR UPDATE OF p',
                $limit - count($variantIds)
            ));
            /** @var list<ProductVariant> $variants */
            $variants = $this->reload(ProductVariant::class, $variantIds);
            /** @var list<Product> $products */
            $products = $this->reload(Product::class, $productIds);
            $targets = [...$variants, ...$products];
            $codes = $targets === [] ? [] : $this->generateMany(count($targets));
            foreach ($targets as $i => $target) {
                $target->setBarcode($codes[$i]);
            }
            $em->flush();
            $remaining = $this->missingCount()['total'];
            $result = ['written' => count($targets), 'variants' => count($variants), 'products' => count($products), 'remaining' => $remaining];
            if ($targets !== []) {
                $this->audit->record(self::AUDIT_RESOURCE, null, 'generate', null, $result + ['scope' => 'catalog', 'first' => $codes[0], 'last' => $codes[count($codes) - 1]], ['barcode']);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }

        return $result;
    }

    /**
     * `$count` codes générés libres. Un numéro tiré de la séquence n'y revient jamais ; si le code existe déjà (saisi à la main), on prend
     * le suivant.
     *
     * @return list<string>
     */
    private function generateMany(int $count): array
    {
        $conn = $this->connection();
        $codes = [];
        for ($round = 0; count($codes) < $count; ++$round) {
            if ($round > 20) {
                throw new \RuntimeException('Code-barres : aucun code généré libre après plusieurs tirages');
            }
            $numbers = $conn->fetchFirstColumn(sprintf("SELECT nextval('%s') FROM generate_series(1, %d)", self::SEQUENCE, $count - count($codes)));
            $drawn = array_map(static fn ($n): string => CatalogBarcode::generated((int) $n), $numbers);
            $taken = $conn->fetchFirstColumn(
                'SELECT barcode FROM product WHERE barcode IN (:codes) UNION SELECT barcode FROM product_variant WHERE barcode IN (:codes)',
                ['codes' => $drawn],
                ['codes' => ArrayParameterType::STRING]
            );
            array_push($codes, ...array_values(array_diff($drawn, $taken)));
        }

        return $codes;
    }

    /**
     * Entités relues depuis la base (l'objet déjà en mémoire reprend la valeur de la ligne), par identifiant croissant.
     *
     * @param class-string $class
     * @param list<int|string> $ids
     *
     * @return list<object>
     */
    private function reload(string $class, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->em()->createQuery(sprintf('SELECT e FROM %s e WHERE e.id IN (:ids) ORDER BY e.id ASC', $class))
            ->setParameter('ids', array_map('intval', $ids))->setHint(Query::HINT_REFRESH, true)->getResult();
    }

    /** Nom lisible de ce qui porte un code : « Casquette » ou « Casquette, Rouge / M » */
    private function label(string $type, int $id): string
    {
        $em = $this->em();
        if ($type === 'product') {
            $name = trim((string) $em->find(Product::class, $id)?->getName());

            return $name !== '' ? $name : sprintf('article n° %d', $id);
        }
        $variant = $em->find(ProductVariant::class, $id);
        $name = trim((string) $variant?->getProductName());
        $parts = array_values(array_filter([trim((string) $variant?->getColor()?->getName()), trim((string) $variant?->getSize()?->getName())], static fn (string $p): bool => $p !== ''));

        return ($name !== '' ? $name : 'article') . ', ' . ($parts !== [] ? implode(' / ', $parts) : sprintf('variante n° %d', $id));
    }

    private function em(): EntityManagerInterface
    {
        return $this->emProvider->getEntityManager();
    }

    private function connection(): Connection
    {
        return $this->em()->getConnection();
    }
}
