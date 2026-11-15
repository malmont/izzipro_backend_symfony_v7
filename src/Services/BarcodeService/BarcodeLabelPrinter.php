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
 */
class BarcodeLabelPrinter
{
    public const MAX_LABELS = 500;
    public const FORMAT = ['code' => 'a4-50x30', 'widthMm' => 50, 'heightMm' => 30];
    public const EAN13 = 'ean13';
    public const CODE128 = 'code128';

    public function __construct(private readonly Environment $twig)
    {
    }

    /** Symbole qui imprimera ce code */
    public static function symbology(string $code): string
    {
        return CatalogBarcode::isEan13($code) ? self::EAN13 : self::CODE128;
    }

    /**
     * @param list<array{title: string, subtitle: ?string, barcode: string, price: ?string, copies: int}> $labels
     */
    public function html(array $labels, string $title = 'Étiquettes'): string
    {
        $total = array_sum(array_map(static fn (array $l): int => max(0, (int) $l['copies']), $labels));
        if ($total > self::MAX_LABELS) {
            throw new \LengthException(sprintf('Au plus %d étiquettes par planche (%d demandées)', self::MAX_LABELS, $total));
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
                'price' => $label['price'] ?? null, 'copies' => $copies, 'image' => $images[$code]];
        }

        return $this->twig->render('barcode/labels.html.twig', ['labels' => $rows, 'title' => $title]);
    }

    /**
     * Le PDF de la planche (contenu binaire).
     *
     * @param list<array{title: string, subtitle: ?string, barcode: string, price: ?string, copies: int}> $labels
     */
    public function pdf(array $labels, string $title = 'Étiquettes'): string
    {
        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($labels, $title));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
