<?php

namespace App\Services\CollectionDashboardService;

use App\Gestion\Services\PurchaseMetricsCalculator;
use App\Services\TenantEntityManagerProvider;

/**
 * Tableau de bord combiné de toutes les collections non supprimées (lot G2, 09/11/2026) : trois requêtes agrégées au
 * total (produits, frais et notes, budgets et dates), taux recalculés sur les sommes. Jusqu'au 09/11/2026, chaque
 * collection était recalculée entité par entité puis les taux de marge étaient additionnés entre collections.
 * Mêmes clés JSON qu'avant ; `days` reste la somme des durées des collections.
 */
class CombinedCollectionDashboardService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly PurchaseMetricsCalculator $calculator)
    {
    }

    /** @return array<string, mixed> */
    /** @param string $kind `manual` (collections manuelles, défaut) ou `period` (périodes du calendrier) : jamais les deux ensemble (lot P1, 10/11/2026) */
    public function getCombinedCollectionsData(string $kind = 'manual'): array
    {
        $kind = $kind === 'period' ? 'period' : 'manual';
        $totals = $kind === 'period' ? $this->calculator->allPeriodsTotals() : $this->calculator->allCollectionsTotals();
        $summary = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COALESCE(SUM(budget_collection), 0) AS budget, MIN(start_date_collection) AS start_date, MAX(end_date_collection) AS end_date,' .
            ' COALESCE(SUM(GREATEST(0, (end_date_collection::date - start_date_collection::date))), 0) AS days FROM collections WHERE del = false AND kind = :kind',
            ['kind' => $kind]
        ) ?: [];
        $data = CollectionDashboardService::data($totals, (int) ($summary['budget'] ?? 0), null, null);
        $data['CollectionDuration'] = [
            'startDate' => isset($summary['start_date']) ? substr((string) $summary['start_date'], 0, 10) : null,
            'endDate' => isset($summary['end_date']) ? substr((string) $summary['end_date'], 0, 10) : null,
            'days' => (int) ($summary['days'] ?? 0),
        ];

        $data['kind'] = $kind;

        return $data;
    }
}
