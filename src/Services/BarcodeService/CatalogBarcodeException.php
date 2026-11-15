<?php

namespace App\Services\BarcodeService;

/**
 * Refus d'un code-barres du catalogue (lot CB-1, `docs/codes-barres.md`) : un code stable que les applications traduisent
 * (`BARCODE_INVALID`, `BARCODE_RESERVED`, `BARCODE_ALREADY_USED`, `BARCODE_ALREADY_SET`, `BARCODE_HAS_VARIANTS`) et un texte en français
 * pour les écrans d'EasyAdmin. Pour `BARCODE_ALREADY_USED`, `holder` dit ce qui porte déjà le code.
 */
final class CatalogBarcodeException extends \DomainException
{
    /**
     * @param array{type: string, id: int, productId: int, label: string}|null $holder
     * @param ?string $current pour `BARCODE_ALREADY_SET` : le code que la fiche porte déjà (lot CB-3)
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?array $holder = null,
        public readonly ?string $current = null,
    ) {
        parent::__construct($message);
    }
}
