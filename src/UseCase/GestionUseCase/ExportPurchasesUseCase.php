<?php

namespace App\UseCase\GestionUseCase;

use App\Services\GestionService\PurchaseExportService;

/** GET /api/gestion/exports/achats.csv : export comptable des achats d'une période (lot G5, 10/11/2026) */
class ExportPurchasesUseCase
{
    public function __construct(private readonly PurchaseExportService $export)
    {
    }

    /** @return array{filename: string, csv: string, export: array<string, mixed>} */
    public function execute(?string $from, ?string $to, ?int $collectionId): array
    {
        $export = $this->export->build($from, $to, $collectionId);

        return ['filename' => sprintf('achats_%s_%s.csv', $export['from'], $export['to']), 'csv' => $this->export->csv($export), 'export' => $export];
    }
}
