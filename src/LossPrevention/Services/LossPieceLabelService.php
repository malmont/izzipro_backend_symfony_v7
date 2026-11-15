<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\Repository\LossPieceRepository;
use App\Services\BarcodeService\BarcodeLabelPrinter;
use App\Services\TenantEntityManagerProvider;

/**
 * Étiquettes des pièces (lot L2-b, 15/11/2026, `docs/prevention-pertes.md` § 13.10) : une étiquette par pièce, avec le code-barres de
 * sa variante (celui que la caisse lit) et le numéro de la pièce. Planche A4 ou rouleau thermique, par l'imprimeur des étiquettes du
 * catalogue (`BarcodeLabelPrinter`). Une lecture : rien n'est écrit.
 */
class LossPieceLabelService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly BarcodeLabelPrinter $printer,
        private readonly LossTexts $texts,
        private readonly LossSite $site,
    ) {
    }

    /**
     * @param array<string, mixed> $body { pieceIds: [int], format?: a4-50x30 | roll-50x30, showPrice? }
     *
     * @return array{content: string, count: int, format: string}
     */
    public function pdf(array $body, string $locale, bool $exercise): array
    {
        $selection = $this->selection($body, $locale, $exercise);

        return ['content' => $this->printer->pdf($selection['labels'], $this->texts->t('piece_labels.title', $locale), $selection['format']),
            'count' => count($selection['labels']), 'format' => $selection['format']];
    }

    /**
     * La sélection demandée, vérifiée, telle qu'elle part à l'impression : une étiquette par pièce, dans l'ordre demandé.
     *
     * @param array<string, mixed> $body
     *
     * @return array{labels: list<array{title: string, subtitle: ?string, barcode: string, copies: int, price: ?string, note: string}>, format: string}
     */
    public function selection(array $body, string $locale, bool $exercise): array
    {
        $errors = [];
        $format = $body['format'] ?? BarcodeLabelPrinter::A4;
        if (!is_string($format) || !isset(BarcodeLabelPrinter::FORMATS[$format])) {
            $errors['format'] = 'INVALID_VALUE';
            $format = BarcodeLabelPrinter::A4;
        }
        if (array_key_exists('showPrice', $body) && !is_bool($body['showPrice'])) {
            $errors['showPrice'] = 'MUST_BE_BOOLEAN';
        }
        $ids = $body['pieceIds'] ?? null;
        if (!is_array($ids) || $ids === [] || !array_is_list($ids)) {
            $errors['pieceIds'] = 'REQUIRED';
        } elseif (array_filter($ids, static fn ($id): bool => !is_int($id) || $id < 1 || $id > 2147483647) !== [] || count(array_unique($ids)) !== count($ids)) {
            $errors['pieceIds'] = 'MUST_BE_DISTINCT_POSITIVE';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $max = BarcodeLabelPrinter::maxLabels($format);
        if (count($ids) > $max) {
            throw new GestionConflictException('LABELS_LIMIT_EXCEEDED', $this->texts->t('piece_labels.error.LABELS_LIMIT_EXCEEDED', $locale, ['max' => $max]), 422, ['max' => $max, 'requested' => count($ids)]);
        }
        /** @var LossPieceRepository $repository */
        $repository = $this->emProvider->getEntityManager()->getRepository(LossPiece::class);
        $pieces = [];
        foreach ($repository->pieces($ids) as $piece) {
            if ((bool) $piece['exercise'] === $exercise) { // une pièce de l'autre monde est « inconnue » ici
                $pieces[(int) $piece['id']] = $piece;
            }
        }
        $unknown = array_values(array_diff($ids, array_keys($pieces)));
        if ($unknown !== []) {
            throw new GestionConflictException('PIECES_NOT_FOUND', $this->texts->t('piece_labels.error.PIECES_NOT_FOUND', $locale), 422, ['pieceIds' => $unknown]);
        }
        $retired = array_values(array_filter($ids, static fn (int $id): bool => $pieces[$id]['status'] === LossPiece::RETIRED));
        if ($retired !== []) {
            throw new GestionConflictException('PIECE_RETIRED', $this->texts->t('piece_labels.error.PIECE_RETIRED', $locale), 409, ['pieceIds' => $retired]);
        }
        $variants = $repository->variants(array_map(static fn (array $p): int => (int) $p['variant_id'], $pieces));
        $showPrice = ($body['showPrice'] ?? false) === true;
        $formatter = new \NumberFormatter($locale === 'en' ? 'en_CA' : 'fr_CA', \NumberFormatter::CURRENCY);
        $currency = $this->site->currency();
        $labels = [];
        $without = [];
        foreach ($ids as $id) { // dans l'ordre demandé
            $variant = $variants[(int) $pieces[$id]['variant_id']] ?? null;
            $catalogue = LossPieceService::catalogue($variant);
            if ($catalogue['barcode'] === null) {
                $without[] = $id;
                continue;
            }
            $price = $variant['price'] !== null ? (int) $variant['price'] : ($variant['product_price'] !== null ? (int) round((float) $variant['product_price']) : null);
            $labels[] = [
                'title' => (string) $catalogue['name'], 'subtitle' => $catalogue['variantLabel'], 'barcode' => (string) $catalogue['barcode'], 'copies' => 1,
                // prix du catalogue hors promotion ; zéro : pas de prix (règle des étiquettes, `docs/codes-barres.md` § 7)
                'price' => $showPrice && $price !== null && $price > 0 ? (string) $formatter->formatCurrency($price / 100, $currency) : null,
                'note' => $this->texts->t($exercise ? 'piece_labels.exercise_note' : 'piece_labels.note', $locale, ['number' => (int) $pieces[$id]['number']]),
            ];
        }
        if ($without !== []) {
            throw new GestionConflictException('BARCODE_MISSING', $this->texts->t('piece_labels.error.BARCODE_MISSING', $locale), 409,
                ['pieceIds' => $without, 'variantIds' => array_values(array_unique(array_map(static fn (int $id): int => (int) $pieces[$id]['variant_id'], $without)))]);
        }

        return ['labels' => $labels, 'format' => $format];
    }
}
