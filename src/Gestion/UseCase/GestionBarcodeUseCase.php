<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\GestionBarcodeService;

/** Codes-barres du catalogue dans l'application de gestion (lot CB-3, `docs/codes-barres.md` § 8) */
class GestionBarcodeUseCase
{
    public function __construct(private readonly GestionBarcodeService $barcodes)
    {
    }

    /** @return array<string, mixed> */
    public function search(mixed $code, string $locale = 'fr'): array
    {
        return $this->barcodes->search($code, $locale);
    }

    /** @return array<string, int> */
    public function missing(): array
    {
        return $this->barcodes->missing();
    }

    /** @return array<string, int> */
    public function generateMissing(): array
    {
        return $this->barcodes->generateMissing();
    }

    /** @return array<string, mixed> */
    public function generate(string $type, int $id, string $locale = 'fr'): array
    {
        return $this->barcodes->generate($type, $id, $locale);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function put(string $type, int $id, array $body, string $locale = 'fr'): array
    {
        return $this->barcodes->put($type, $id, $body, $locale);
    }

    /** @param array<string, mixed> $body @return array{content: string, count: int} */
    public function pdf(array $body, string $locale = 'fr'): array
    {
        return $this->barcodes->pdf($body, $locale);
    }
}
