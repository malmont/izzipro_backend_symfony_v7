<?php

namespace App\Services\AppKeyService;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Code QR d'une clé d'activation (PNG, endroid/qr-code 6 + GD). Contenu : la clé elle-même (« IZ-POS-… ») ; une
 * application la lit au scan et appelle POST /api/app/activate comme pour une saisie.
 */
class AppQrCodeGenerator
{
    public function png(string $key, int $size = 440): string
    {
        return (new Builder(writer: new PngWriter(), data: $key, errorCorrectionLevel: ErrorCorrectionLevel::Medium, size: $size, margin: 16))
            ->build()->getString();
    }
}
