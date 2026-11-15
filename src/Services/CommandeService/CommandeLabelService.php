<?php

namespace App\Services\CommandeService;

use App\Entity\Commande;
use App\Entity\CommandeLine;
use App\Entity\Entreprise;
use App\Entity\ProductVariant;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Services\BarcodeService\BarcodeLabelPrinter;
use App\Services\BarcodeService\CatalogBarcodeService;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;

/**
 * Étiquettes de codes-barres d'une commande fournisseur reçue (lot CB-2, 15/11/2026, `docs/codes-barres.md` § 7) : la liste des
 * étiquettes à imprimer, la génération des codes manquants, le PDF de la sélection. Aucune écriture de stock : la réception ne change pas.
 *
 * Une étiquette désigne la PIÈCE reçue : la variante qui a reçu le stock (la réception en crée toujours une).
 * - Quantité reçue par variante : le solde des mouvements de stock de la commande (réceptions moins annulations). Le reste d'une ligne
 *   « réputée reçue » (rattachement, reprise : pas de mouvement) va à la variante de la ligne, sinon à l'unique variante de l'article ;
 *   si l'article en a plusieurs, la répartition n'est pas connue : chacune est listée, `quantityReceived` à null, le nombre proposé est
 *   son stock. Un article sans aucune variante n'est pas vendable : il est rendu à part (`skipped`, `NO_VARIANT`).
 * - Code imprimé : celui de la variante ; à défaut celui de l'article quand elle est sa seule variante (`barcodeOf: product`).
 * - Prix : celui du catalogue, tel qu'affiché au client (variante, sinon article), hors promotion et sans calcul de taxe ; il est TTC
 *   sur un site réglé « Prix affichés taxes comprises », HT sinon (`priceIncludesTax`). Un prix à zéro vaut « pas de prix » (`null`).
 */
class CommandeLabelService
{
    public const BARCODE_MISSING = 'BARCODE_MISSING';
    public const NO_VARIANT = 'NO_VARIANT';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CatalogBarcodeService $barcodes,
        private readonly BarcodeLabelPrinter $printer,
        private readonly TenantCurrencyProvider $currency,
    ) {
    }

    /** @throws GestionConflictException 404 COMMANDE_NOT_FOUND */
    public function commande(int $id): Commande
    {
        $commande = $this->emProvider->getEntityManager()->find(Commande::class, $id);
        if ($commande === null) {
            throw new GestionConflictException('COMMANDE_NOT_FOUND', 'Commande introuvable.', 404);
        }

        return $commande;
    }

    /**
     * `GET /api/commandes/{id}/etiquettes`.
     *
     * @return array<string, mixed>
     */
    public function labels(Commande $commande): array
    {
        $em = $this->emProvider->getEntityManager();
        $includesTax = (bool) $em->getRepository(Entreprise::class)->findOneBy([])?->isPricesIncludeTax();
        // Ce que les mouvements de stock tracent : solde par ligne et par variante
        $traced = [];
        foreach ($em->getConnection()->fetchAllAssociative(
            'SELECT m.commande_line_id AS line_id, m.product_variant_id AS variant_id, SUM(m.quantity) AS quantity FROM inventory_movements m'
            . ' JOIN commande_line l ON l.id = m.commande_line_id WHERE l.commande_id = ? AND m.product_variant_id IS NOT NULL'
            . ' GROUP BY m.commande_line_id, m.product_variant_id HAVING SUM(m.quantity) > 0 ORDER BY m.commande_line_id, m.product_variant_id',
            [$commande->getId()]
        ) as $row) {
            $traced[(int) $row['line_id']][(int) $row['variant_id']] = (int) $row['quantity'];
        }
        /** @var array<int, array{variant: ProductVariant, quantity: ?int}> $byVariant dans l'ordre des lignes */
        $byVariant = [];
        $skipped = [];
        $add = static function (ProductVariant $variant, ?int $quantity) use (&$byVariant): void {
            $id = (int) $variant->getId();
            if (!isset($byVariant[$id])) {
                $byVariant[$id] = ['variant' => $variant, 'quantity' => $quantity];
            } elseif ($quantity !== null) {
                $byVariant[$id]['quantity'] = ($byVariant[$id]['quantity'] ?? 0) + $quantity;
            }
        };
        /** @var CommandeLine $line */
        foreach ($em->getRepository(CommandeLine::class)->forCommande($commande) as $line) {
            $product = $line->getProduct();
            if ($product === null || $line->getQuantityReceived() <= 0) {
                continue;
            }
            $tracedTotal = 0;
            foreach ($traced[(int) $line->getId()] ?? [] as $variantId => $quantity) {
                $variant = $em->find(ProductVariant::class, $variantId);
                if ($variant !== null) {
                    $add($variant, $quantity);
                }
                $tracedTotal += $quantity;
            }
            $deemed = $line->getQuantityReceived() - $tracedTotal; // reçu sans mouvement : ligne « réputée reçue »
            if ($deemed <= 0) {
                continue;
            }
            $variants = array_values($product->getVariants()->toArray());
            usort($variants, static fn (ProductVariant $a, ProductVariant $b): int => $a->getId() <=> $b->getId());
            if ($line->getVariant() !== null) {
                $add($line->getVariant(), $deemed);
            } elseif (count($variants) === 1) {
                $add($variants[0], $deemed);
            } elseif ($variants === []) {
                $skipped[] = ['lineId' => $line->getId(), 'product' => ['id' => $product->getId(), 'name' => $product->getName(), 'code' => $product->getCode()],
                    'quantityReceived' => $deemed, 'reason' => self::NO_VARIANT];
            } else {
                foreach ($variants as $variant) {
                    $add($variant, null); // répartition inconnue entre les variantes
                }
            }
        }

        $labels = [];
        $missing = [];
        $printable = 0;
        $total = 0;
        foreach ($byVariant as $id => ['variant' => $variant, 'quantity' => $quantity]) {
            $product = $variant->getProduct();
            $code = $variant->getBarcode();
            $of = $code !== null ? 'variant' : null;
            if ($code === null && $product?->getBarcode() !== null && $product->getVariants()->count() === 1) {
                $code = $product->getBarcode();
                $of = 'product';
            }
            $copies = $quantity ?? max(0, $variant->getStockQuantity());
            $subtitle = implode(' / ', array_filter([trim((string) $variant->getColor()?->getName()), trim((string) $variant->getSize()?->getName())], static fn (string $p): bool => $p !== ''));
            $price = $variant->getPrice() ?? ($product?->getPrice() !== null ? (int) round((float) $product->getPrice()) : null);
            if ($price !== null && $price <= 0) {
                $price = null; // prix à zéro : « pas de prix » (le prix de l'article est obligatoire en base) ; l'étiquette s'imprime sans prix
            }
            $labels[] = [
                'variantId' => $id,
                'product' => ['id' => $product?->getId(), 'name' => $product?->getName(), 'code' => $product?->getCode()],
                'variant' => ['id' => $id, 'size' => $variant->getSize()?->getName(), 'color' => $variant->getColor()?->getName()],
                'title' => (string) $product?->getName(), 'subtitle' => $subtitle !== '' ? $subtitle : null,
                'barcode' => $code, 'barcodeOf' => $of, 'symbology' => $code !== null ? BarcodeLabelPrinter::symbology($code) : null,
                'priceCents' => $price, 'priceIncludesTax' => $includesTax,
                'quantityReceived' => $quantity, 'copies' => $copies,
                'printable' => $code !== null, 'reason' => $code !== null ? null : self::BARCODE_MISSING,
            ];
            if ($code === null) {
                $missing[] = $id;
            } else {
                ++$printable;
                $total += $copies;
            }
        }

        return [
            'commande' => ['id' => $commande->getId(), 'name' => $commande->getName(), 'receptionStatus' => $commande->receptionStatus()],
            'format' => BarcodeLabelPrinter::FORMAT,
            'currency' => $this->currency->code(),
            'pricesIncludeTax' => $includesTax,
            'maxLabels' => BarcodeLabelPrinter::MAX_LABELS,
            // formats que le PDF sait rendre (`format` du corps de l'appel 3), chacun avec son plafond ; `format` ci-dessus est celui par défaut
            'formats' => BarcodeLabelPrinter::formats(),
            'labels' => $labels,
            'missing' => ['count' => count($missing), 'variantIds' => $missing],
            'skipped' => $skipped,
            'totals' => ['lines' => count($labels), 'printable' => $printable, 'labels' => $total],
        ];
    }

    /**
     * `POST /api/commandes/{id}/etiquettes/codes` : un code généré pour les variantes reçues qui n'en ont pas (toutes, ou celles de
     * `variantIds`). Ne remplace jamais un code ; idempotent.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> `generated` puis la liste à jour
     */
    public function generate(Commande $commande, array $data): array
    {
        $list = $this->labels($commande);
        $wanted = $list['missing']['variantIds'];
        if (array_key_exists('variantIds', $data)) {
            $ids = $data['variantIds'];
            if (!is_array($ids) || array_filter($ids, static fn ($id): bool => !is_int($id) || $id < 1) !== []) {
                throw new GestionValidationException(['variantIds' => 'MUST_BE_INTEGER_LIST'], 'Liste d\'identifiants de variantes attendue');
            }
            $this->assertInCommande($ids, $list);
            $wanted = array_values(array_intersect($list['missing']['variantIds'], $ids)); // une variante qui a déjà son code : rien à faire
        }
        $written = $this->barcodes->generateForVariants($wanted, ['scope' => 'commande', 'commandeId' => $commande->getId()]);
        $generated = [];
        foreach ($written as $variantId => $barcode) {
            $generated[] = ['variantId' => $variantId, 'barcode' => $barcode];
        }

        return ['generated' => $generated] + $this->labels($commande);
    }

    /**
     * `POST /api/commandes/{id}/etiquettes/pdf` : la planche de la sélection.
     *
     * @param array<string, mixed> $data { labels: [{ variantId, copies }], showPrice?, format? (a4-50x30 | roll-50x30) }
     *
     * @return array{content: string, count: int, format: string}
     */
    public function pdf(Commande $commande, array $data, string $locale = 'fr'): array
    {
        $labels = $this->selection($commande, $data, $locale);
        $format = (string) BarcodeLabelPrinter::formatAsked($data['format'] ?? null); // déjà vérifié par selection()

        return ['content' => $this->printer->pdf($labels, sprintf('Étiquettes de la commande %s', (string) ($commande->getName() ?? $commande->getId())), $format),
            'count' => array_sum(array_column($labels, 'copies')), 'format' => $format];
    }

    /**
     * La sélection demandée, vérifiée, telle qu'elle part à l'impression (titre, variante, code, prix écrit ou null, exemplaires).
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{title: string, subtitle: ?string, barcode: string, price: ?string, copies: int}>
     */
    public function selection(Commande $commande, array $data, string $locale = 'fr'): array
    {
        $selection = $data['labels'] ?? null;
        if (!is_array($selection) || $selection === [] || !array_is_list($selection)) {
            throw new GestionValidationException(['labels' => 'REQUIRED'], 'Sélection d\'étiquettes attendue');
        }
        $errors = [];
        $copiesOf = [];
        foreach ($selection as $i => $item) {
            $variantId = is_array($item) ? ($item['variantId'] ?? null) : null;
            $copies = is_array($item) ? ($item['copies'] ?? null) : null;
            if (!is_int($variantId) || $variantId < 1) {
                $errors["labels[$i].variantId"] = 'MUST_BE_POSITIVE';
            } elseif (isset($copiesOf[$variantId])) {
                $errors["labels[$i].variantId"] = 'DUPLICATE';
            }
            if (!is_int($copies) || $copies < 1) {
                $errors["labels[$i].copies"] = 'MUST_BE_POSITIVE';
            }
            if (is_int($variantId) && is_int($copies)) {
                $copiesOf[$variantId] = $copies;
            }
        }
        if (array_key_exists('showPrice', $data) && !is_bool($data['showPrice'])) {
            $errors['showPrice'] = 'MUST_BE_BOOLEAN';
        }
        $format = BarcodeLabelPrinter::formatAsked($data['format'] ?? null);
        if ($format === null) {
            $errors['format'] = 'INVALID_VALUE';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Sélection d\'étiquettes invalide');
        }
        $requested = array_sum($copiesOf);
        $max = BarcodeLabelPrinter::maxLabels($format); // 500 en planche A4, 200 en rouleau (une page par étiquette)
        if ($requested > $max) {
            throw new GestionConflictException('LABELS_LIMIT_EXCEEDED', sprintf('Au plus %d étiquettes par impression.', $max), 422, ['max' => $max, 'requested' => $requested]);
        }
        $list = $this->labels($commande);
        $this->assertInCommande(array_keys($copiesOf), $list);
        $rows = array_column($list['labels'], null, 'variantId');
        $without = array_values(array_filter(array_keys($copiesOf), static fn (int $id): bool => !$rows[$id]['printable']));
        if ($without !== []) {
            throw new GestionConflictException(self::BARCODE_MISSING, 'Des variantes de la sélection n\'ont pas de code-barres : générez-les d\'abord, ou retirez-les.', 409, ['variantIds' => $without]);
        }
        $showPrice = ($data['showPrice'] ?? false) === true;
        $formatter = new \NumberFormatter($locale === 'en' ? 'en_CA' : 'fr_CA', \NumberFormatter::CURRENCY);
        $labels = [];
        foreach ($copiesOf as $variantId => $copies) {
            $row = $rows[$variantId];
            $labels[] = ['title' => $row['title'], 'subtitle' => $row['subtitle'], 'barcode' => $row['barcode'], 'copies' => $copies,
                'price' => $showPrice && $row['priceCents'] !== null ? (string) $formatter->formatCurrency($row['priceCents'] / 100, $list['currency']) : null];
        }

        return $labels;
    }

    /**
     * @param list<int> $variantIds
     * @param array<string, mixed> $list
     *
     * @throws GestionConflictException 422 VARIANT_NOT_IN_COMMANDE
     */
    private function assertInCommande(array $variantIds, array $list): void
    {
        $unknown = array_values(array_diff($variantIds, array_column($list['labels'], 'variantId')));
        if ($unknown !== []) {
            throw new GestionConflictException('VARIANT_NOT_IN_COMMANDE', 'Des variantes ne font pas partie de ce qui a été reçu sur cette commande.', 422, ['variantIds' => $unknown]);
        }
    }
}
