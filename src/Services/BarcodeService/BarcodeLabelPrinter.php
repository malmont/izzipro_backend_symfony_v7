<?php

namespace App\Services\BarcodeService;

use Dompdf\Dompdf;
use Dompdf\Options;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Twig\Environment;

/**
 * Planches d'étiquettes de codes-barres (lot CB-2, `docs/codes-barres.md`) : 50 × 30 mm sur A4, nom de l'article, variante, barres, code
 * en clair, prix si on le demande. Symbole : EAN-13 pour un code de 13 chiffres à clé juste, sinon Code 128. Au plus `MAX_LABELS`
 * étiquettes par planche (un rendu démesuré bloquerait le serveur). Une seule image par code, quel que soit le nombre d'exemplaires.
 *
 * Deux formats (lot L2-b, `docs/prevention-pertes.md` § 13) : `a4-50x30`, la planche A4 ; `roll-50x30`, une étiquette par page de
 * 50 × 30 mm, pour une imprimante thermique à rouleau. Une étiquette peut porter une ligne de plus (`note` : le numéro d'une pièce).
 */
class BarcodeLabelPrinter
{
    public const MAX_LABELS = 500;
    /** En rouleau, une page par étiquette : le rendu est plus lourd (200 étiquettes en 4 s, 500 en 16 s, mesuré le 15/11/2026) */
    public const ROLL_MAX_LABELS = 200;
    public const A4 = 'a4-50x30';
    public const ROLL = 'roll-50x30';
    /** Le format par défaut, tel que les routes le rendent (lot CB-2) */
    public const FORMAT = ['code' => self::A4, 'widthMm' => 50, 'heightMm' => 30];
    public const FORMATS = [
        self::A4 => ['code' => self::A4, 'widthMm' => 50, 'heightMm' => 30],
        self::ROLL => ['code' => self::ROLL, 'widthMm' => 50, 'heightMm' => 30],
    ];
    public const EAN13 = 'ean13';
    public const CODE128 = 'code128';

    public function __construct(private readonly Environment $twig)
    {
    }

    /** Étiquettes au plus par impression, selon le format */
    public static function maxLabels(string $format = self::A4): int
    {
        return $format === self::ROLL ? self::ROLL_MAX_LABELS : self::MAX_LABELS;
    }

    /**
     * Le format demandé par une route (`format` du corps) : absent, la planche A4 ; sinon un des formats connus, ou null si la valeur
     * n'en est pas un (la route répond alors `format: INVALID_VALUE`).
     */
    public static function formatAsked(mixed $asked): ?string
    {
        if ($asked === null) {
            return self::A4;
        }

        return is_string($asked) && isset(self::FORMATS[$asked]) ? $asked : null;
    }

    /** @return list<array{code: string, widthMm: int, heightMm: int, maxLabels: int}> les formats proposés, avec leur plafond */
    public static function formats(): array
    {
        return array_map(static fn (array $f): array => $f + ['maxLabels' => self::maxLabels($f['code'])], array_values(self::FORMATS));
    }

    /** Symbole qui imprimera ce code */
    public static function symbology(string $code): string
    {
        return CatalogBarcode::isEan13($code) ? self::EAN13 : self::CODE128;
    }

    /**
     * @param list<array{title: string, subtitle: ?string, barcode: string, price: ?string, copies: int, note?: ?string}> $labels
     */
    public function html(array $labels, string $title = 'Étiquettes', string $format = self::A4): string
    {
        if (!isset(self::FORMATS[$format])) {
            throw new \InvalidArgumentException('Format d\'étiquette inconnu');
        }
        $total = array_sum(array_map(static fn (array $l): int => max(0, (int) $l['copies']), $labels));
        if ($total > self::maxLabels($format)) {
            throw new \LengthException(sprintf('Au plus %d étiquettes par planche (%d demandées)', self::maxLabels($format), $total));
        }
        $generator = new BarcodeGeneratorPNG();
        $images = [];
        $rows = [];
        foreach ($labels as $label) {
            $copies = (int) $label['copies'];
            if ($copies < 1) {
                continue;
            }
            $code = (string) $label['barcode'];
            $images[$code] ??= base64_encode($generator->getBarcode($code, self::symbology($code) === self::EAN13 ? $generator::TYPE_EAN_13 : $generator::TYPE_CODE_128, 2, 60));
            $rows[] = ['title' => mb_substr((string) $label['title'], 0, 34), 'subtitle' => mb_substr((string) ($label['subtitle'] ?? ''), 0, 38), 'barcode' => $code,
                'price' => $label['price'] ?? null, 'copies' => $copies, 'image' => $images[$code], 'note' => mb_substr((string) ($label['note'] ?? ''), 0, 38)];
        }

        return $this->twig->render('barcode/labels.html.twig', ['labels' => $rows, 'title' => $title, 'roll' => $format === self::ROLL]);
    }

    /**
     * Le PDF de la planche (contenu binaire).
     *
     * @param list<array{title: string, subtitle: ?string, barcode: string, price: ?string, copies: int, note?: ?string}> $labels
     */
    public function pdf(array $labels, string $title = 'Étiquettes', string $format = self::A4): string
    {
        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($labels, $title, $format));
        if ($format === self::ROLL) {
            // une page par étiquette, à sa taille : 50 × 30 mm en points (1 mm = 72 / 25,4 pt)
            $dompdf->setPaper([0, 0, self::FORMATS[$format]['widthMm'] * 72 / 25.4, self::FORMATS[$format]['heightMm'] * 72 / 25.4]);
        } else {
            $dompdf->setPaper('A4', 'portrait');
        }
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
