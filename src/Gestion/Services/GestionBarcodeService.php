<?php

namespace App\Gestion\Services;

use App\Entity\Entreprise;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\BarcodeService\BarcodeCatalogList;
use App\Services\BarcodeService\BarcodeLabelPrinter;
use App\Services\BarcodeService\CatalogBarcode;
use App\Services\BarcodeService\CatalogBarcodeException;
use App\Services\BarcodeService\CatalogBarcodeService;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Codes-barres du catalogue dans l'application de gestion (lot CB-3, 15/11/2026, `docs/codes-barres.md` § 8) : chercher ce que porte un
 * code, compter et générer les codes manquants, générer le code d'une fiche, poser ou retirer un code saisi ou scanné, imprimer des
 * étiquettes hors commande.
 *
 * Aucune règle nouvelle : tout passe par `CatalogBarcodeService` (un code désigne une seule chose sur le site, garanti par la base ;
 * un code n'est jamais remplacé par un code généré ; mêmes refus). Ce service met en forme les réponses, traduit les refus dans la
 * langue demandée (`translations/gestion.{fr,en}.yaml`, `barcodes.*`) et marque chaque écriture au journal du site (`source: gestion`).
 */
class GestionBarcodeService
{
    public const SOURCE = ['source' => 'gestion'];
    public const CHECK_DIGIT = 'BARCODE_CHECK_DIGIT';

    private const STATUS = [
        CatalogBarcode::INVALID => 422,
        CatalogBarcode::RESERVED => 422,
        CatalogBarcodeService::ALREADY_USED => 409,
        CatalogBarcodeService::ALREADY_SET => 409,
        CatalogBarcodeService::HAS_VARIANTS => 409,
    ];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CatalogBarcodeService $barcodes,
        private readonly BarcodeCatalogList $catalog,
        private readonly BarcodeLabelPrinter $printer,
        private readonly TenantCurrencyProvider $currency,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Ce que porte un code scanné ou saisi (forme canonique), articles non vendus en caisse compris.
     *
     * @return array{code: string, found: bool, refusal: ?string, currency: string, sheet: ?array<string, mixed>}
     */
    public function search(mixed $raw, string $locale): array
    {
        $code = is_string($raw) ? CatalogBarcode::normalize($raw) : null;
        if ($code === null) {
            throw new GestionValidationException(['code' => 'REQUIRED'], $this->t('error.CODE_REQUIRED', $locale));
        }
        $holder = $this->barcodes->holder($code);
        $sheet = null;
        if ($holder !== null) {
            $target = $this->emProvider->getEntityManager()->find($holder['type'] === 'variant' ? ProductVariant::class : Product::class, $holder['id']);
            $sheet = $target !== null ? $this->sheet($target) : null;
        }

        return [
            'code' => $code,
            'found' => $sheet !== null,
            // quand rien ne le porte : ce code pourrait-il être posé sur une fiche ? (null : oui)
            'refusal' => $sheet === null ? CatalogBarcode::refusal($code) : null,
            'currency' => $this->currency->code(),
            'sheet' => $sheet,
        ];
    }

    /** @return array{variants: int, products: int, total: int, limit: int} */
    public function missing(): array
    {
        return $this->barcodes->missingCount() + ['limit' => CatalogBarcodeService::BULK_LIMIT];
    }

    /** @return array{written: int, variants: int, products: int, remaining: int, limit: int} */
    public function generateMissing(): array
    {
        return $this->barcodes->generateMissing(CatalogBarcodeService::BULK_LIMIT, self::SOURCE) + ['limit' => CatalogBarcodeService::BULK_LIMIT];
    }

    /**
     * Un code généré pour une fiche qui n'en a pas.
     *
     * @return array{barcode: string, sheet: array<string, mixed>}
     */
    public function generate(string $type, int $id, string $locale): array
    {
        $target = $this->target($type, $id, $locale);
        try {
            $code = $this->barcodes->generateFor($target, self::SOURCE);
        } catch (CatalogBarcodeException $e) {
            throw $this->refusal($e, $locale);
        }

        return ['barcode' => $code, 'sheet' => $this->sheet($target)];
    }

    /**
     * Pose un code saisi ou scanné sur une fiche ; `barcode` vide ou null : le retire. Une fiche qui porte déjà un autre code ne le
     * perd (remplacé ou retiré) qu'avec `replace: true` ; sinon 409 `BARCODE_ALREADY_SET { current }`.
     *
     * @param array<string, mixed> $body { barcode, replace? }
     *
     * @return array{barcode: ?string, changed: bool, warning: ?array{code: string, message: string}, sheet: array<string, mixed>}
     */
    public function put(string $type, int $id, array $body, string $locale): array
    {
        if (!array_key_exists('barcode', $body) || ($body['barcode'] !== null && !is_string($body['barcode']))) {
            throw new GestionValidationException(['barcode' => array_key_exists('barcode', $body) ? 'MUST_BE_STRING' : 'REQUIRED'], $this->t('error.BARCODE_FIELD', $locale));
        }
        if (array_key_exists('replace', $body) && !is_bool($body['replace'])) {
            throw new GestionValidationException(['replace' => 'MUST_BE_BOOLEAN'], $this->t('error.BARCODE_FIELD', $locale));
        }
        $target = $this->target($type, $id, $locale);
        try {
            $written = $this->barcodes->assign($target, $body['barcode'], ($body['replace'] ?? false) === true, self::SOURCE);
        } catch (CatalogBarcodeException $e) {
            throw $this->refusal($e, $locale, true);
        }
        $warns = $written['changed'] && $written['barcode'] !== null && CatalogBarcode::hasWrongCheckDigit($written['barcode']);

        return [
            'barcode' => $written['barcode'],
            'changed' => $written['changed'],
            'warning' => $warns ? ['code' => self::CHECK_DIGIT, 'message' => $this->t('warning.' . self::CHECK_DIGIT, $locale)] : null,
            'sheet' => $this->sheet($target),
        ];
    }

    /**
     * Planche d'étiquettes d'une sélection de variantes ou d'articles sans variante, hors commande (même planche que la réception).
     *
     * @param array<string, mixed> $body { labels: [{ variantId | productId, copies }], showPrice? }
     *
     * @return array{content: string, count: int}
     */
    public function pdf(array $body, string $locale): array
    {
        $selection = $body['labels'] ?? null;
        if (!is_array($selection) || $selection === [] || !array_is_list($selection)) {
            throw new GestionValidationException(['labels' => 'REQUIRED'], $this->t('error.LABELS_REQUIRED', $locale));
        }
        $errors = [];
        $copiesOf = [];
        foreach ($selection as $i => $item) {
            $variantId = is_array($item) ? ($item['variantId'] ?? null) : null;
            $productId = is_array($item) ? ($item['productId'] ?? null) : null;
            $copies = is_array($item) ? ($item['copies'] ?? null) : null;
            $key = null;
            if (($variantId === null) === ($productId === null)) {
                $errors["labels[$i]"] = 'ONE_TARGET_REQUIRED'; // variantId OU productId, pas les deux, pas aucun
            } elseif (!is_int($variantId ?? $productId) || ($variantId ?? $productId) < 1 || ($variantId ?? $productId) > 999999999) {
                $errors[$variantId !== null ? "labels[$i].variantId" : "labels[$i].productId"] = 'MUST_BE_POSITIVE';
            } else {
                $key = $variantId !== null ? "variant-$variantId" : "product-$productId";
                if (isset($copiesOf[$key])) {
                    $errors["labels[$i]"] = 'DUPLICATE';
                }
            }
            if (!is_int($copies) || $copies < 1) {
                $errors["labels[$i].copies"] = 'MUST_BE_POSITIVE';
            } elseif ($key !== null) {
                $copiesOf[$key] = $copies;
            }
        }
        if (array_key_exists('showPrice', $body) && !is_bool($body['showPrice'])) {
            $errors['showPrice'] = 'MUST_BE_BOOLEAN';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, $this->t('error.LABELS_INVALID', $locale));
        }
        $requested = array_sum($copiesOf);
        if ($requested > BarcodeLabelPrinter::MAX_LABELS) {
            throw new GestionConflictException('LABELS_LIMIT_EXCEEDED', $this->t('error.LABELS_LIMIT_EXCEEDED', $locale, ['%max%' => BarcodeLabelPrinter::MAX_LABELS]), 422, ['max' => BarcodeLabelPrinter::MAX_LABELS, 'requested' => $requested]);
        }
        $rows = $this->catalog->rowsFor(array_keys($copiesOf));
        $unknown = array_keys(array_diff_key($copiesOf, $rows));
        if ($unknown !== []) {
            throw new GestionConflictException('SHEET_NOT_FOUND', $this->t('error.SHEET_NOT_FOUND', $locale), 422, self::ids($unknown));
        }
        $without = array_keys(array_filter($rows, static fn (array $row): bool => $row['barcode'] === null));
        if ($without !== []) {
            throw new GestionConflictException('BARCODE_MISSING', $this->t('error.BARCODE_MISSING', $locale), 409, self::ids($without));
        }
        $showPrice = ($body['showPrice'] ?? false) === true;
        $formatter = new \NumberFormatter($locale === 'en' ? 'en_CA' : 'fr_CA', \NumberFormatter::CURRENCY);
        $currency = $this->currency->code();
        $labels = [];
        foreach ($copiesOf as $key => $copies) {
            $row = $rows[$key];
            $labels[] = ['title' => $row['title'], 'subtitle' => $row['subtitle'], 'barcode' => $row['barcode'], 'copies' => $copies,
                'price' => $showPrice && $row['priceCents'] !== null ? (string) $formatter->formatCurrency($row['priceCents'] / 100, $currency) : null];
        }

        return ['content' => $this->printer->pdf($labels, $this->t('pdf_title', $locale)), 'count' => $requested];
    }

    /** @throws GestionConflictException 404 `VARIANT_NOT_FOUND` ou `PRODUCT_NOT_FOUND` */
    private function target(string $type, int $id, string $locale): Product|ProductVariant
    {
        $target = $this->emProvider->getEntityManager()->find($type === 'variant' ? ProductVariant::class : Product::class, $id);
        if ($target === null) {
            $code = $type === 'variant' ? 'VARIANT_NOT_FOUND' : 'PRODUCT_NOT_FOUND';
            throw new GestionConflictException($code, $this->t("error.$code", $locale), 404);
        }

        return $target;
    }

    /**
     * Une fiche telle que l'application la montre : de quoi l'afficher et l'ouvrir (`productId` et `variantId` toujours rendus).
     *
     * @return array<string, mixed>
     */
    private function sheet(Product|ProductVariant $target): array
    {
        $variant = $target instanceof ProductVariant ? $target : null;
        $product = $variant !== null ? $variant->getProduct() : $target;
        $label = $variant === null ? '' : implode(' / ', array_filter(
            [trim((string) $variant->getColor()?->getName()), trim((string) $variant->getSize()?->getName())],
            static fn (string $part): bool => $part !== ''
        ));
        $price = $variant?->getPrice() ?? ($product?->getPrice() !== null ? (int) round((float) $product->getPrice()) : null);
        // Stock : celui de la variante ; pour un article, la somme de ses variantes (null s'il n'en a aucune : pas encore vendable)
        $stock = $variant?->getStockQuantity();
        if ($variant === null && $product !== null && !$product->getVariants()->isEmpty()) {
            $stock = array_sum(array_map(static fn (ProductVariant $v): int => $v->getStockQuantity(), $product->getVariants()->toArray()));
        }
        $code = $target->getBarcode();

        return [
            'type' => $variant !== null ? 'variant' : 'product',
            'productId' => $product?->getId(),
            'variantId' => $variant?->getId(),
            'name' => (string) $product?->getName(),
            'variantLabel' => $label !== '' ? $label : null,
            'barcode' => $code,
            'symbology' => $code !== null ? BarcodeLabelPrinter::symbology($code) : null,
            'isPos' => (bool) $product?->isPos(),
            'isWeb' => (bool) $product?->isWeb(),
            'stock' => $stock,
            // prix du catalogue tel qu'affiché au client, hors promotion ; zéro : pas de prix (comme les étiquettes)
            'priceCents' => $price !== null && $price > 0 ? $price : null,
            'priceIncludesTax' => (bool) $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([])?->isPricesIncludeTax(),
        ];
    }

    /** Refus de la règle des codes-barres, rendu à la forme de la gestion et dans la langue demandée */
    private function refusal(CatalogBarcodeException $e, string $locale, bool $replacing = false): GestionConflictException
    {
        $details = [];
        $key = 'error.' . $e->errorCode;
        $params = [];
        if ($e->errorCode === CatalogBarcodeService::ALREADY_USED) {
            $holder = $e->holder;
            $details['holder'] = $holder === null ? null : $holder + ['variantId' => $holder['type'] === 'variant' ? $holder['id'] : null];
            $params = ['%label%' => (string) ($holder['label'] ?? '')];
            $key .= $holder === null ? '_UNKNOWN' : '';
        }
        if ($e->errorCode === CatalogBarcodeService::ALREADY_SET) {
            $details['current'] = $e->current;
            if ($replacing) {
                // la fiche porte un autre code : à confirmer (`replace: true`), pour le remplacer ou le retirer
                $key .= '_REPLACE';
                $params = ['%current%' => (string) $e->current];
            }
        }

        return new GestionConflictException($e->errorCode, $this->t($key, $locale, $params), self::STATUS[$e->errorCode] ?? 409, $details, $e);
    }

    /**
     * @param list<string> $keys `variant-412`, `product-95`
     *
     * @return array{variantIds: list<int>, productIds: list<int>}
     */
    private static function ids(array $keys): array
    {
        $ids = ['variantIds' => [], 'productIds' => []];
        foreach ($keys as $key) {
            [$type, $id] = explode('-', (string) $key, 2);
            $ids[$type === 'variant' ? 'variantIds' : 'productIds'][] = (int) $id;
        }

        return $ids;
    }

    /** @param array<string, scalar> $params */
    private function t(string $key, string $locale, array $params = []): string
    {
        return $this->translator->trans('barcodes.' . $key, $params, 'gestion', $locale);
    }
}
