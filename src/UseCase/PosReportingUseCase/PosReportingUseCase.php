<?php

namespace App\UseCase\PosReportingUseCase;

use App\Entity\User;
use App\Services\AccountingService\PosAccountingExportService;
use App\Services\PosStatisticsService\PosCashierStatisticsService;

/** Statistiques par caissier et exports comptables de la caisse (F14) */
class PosReportingUseCase
{
    public function __construct(private readonly PosCashierStatisticsService $cashiers, private readonly PosAccountingExportService $exports)
    {
    }

    public function cashiers(User $actor, array $query): array
    {
        return $this->cashiers->compute($actor, $query['from'] ?? null, $query['to'] ?? null, $query['cashRegisterId'] ?? null, $query['cashierId'] ?? null);
    }

    public function accounting(User $actor, array $query, string $format): array
    {
        return $this->exports->build($actor, $query['from'] ?? null, $query['to'] ?? null, $query['cashRegisterId'] ?? null, $format);
    }

    public function summaryCsv(array $export): string
    {
        return $this->exports->summaryCsv($export);
    }

    public function entriesCsv(array $export, string $format): string
    {
        return $this->exports->entriesCsv($export, $format);
    }
}
