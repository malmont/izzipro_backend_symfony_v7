<?php

namespace App\Services\CollectionDashboardService;

use App\Dto\DashboardCollectionDTO;
use App\Entity\Collections;
use App\Services\GestionService\PurchaseMetricsCalculator;

/**
 * Tableau de bord d'une collection (lot G2, 09/11/2026) : chiffres calculés par `PurchaseMetricsCalculator` (agrégats
 * SQL : produits de toutes les commandes, frais de port, notes de frais ; cents entiers, taux arrondis), mêmes clés JSON
 * qu'avant. Jusqu'au 09/11/2026, le calcul parcourait commandes → produits (une requête par commande).
 */
class CollectionDashboardService
{
    public function __construct(private readonly PurchaseMetricsCalculator $calculator)
    {
    }

    public function calculateDashboardMetrics(Collections $collection): DashboardCollectionDTO
    {
        $totals = $this->calculator->collectionTotals((int) $collection->getId());

        return DashboardCollectionDTO::fromArray(self::data($totals, (int) $collection->getBudgetCollection(), $collection->getStartDateCollection(), $collection->getEndDateCollection()));
    }

    /**
     * Blocs d'un tableau de bord de collection(s) à partir des totaux (partagé avec le combiné).
     *
     * @param array{purchaseCost: int, stockValue: int, itemCount: int, modelCount: int, averageMultiplier: float, shippingCost: int, expenseCost: int, orderCount: int} $totals
     * @return array<string, mixed>
     */
    public static function data(array $totals, int $generalBudget, ?\DateTimeInterface $start, ?\DateTimeInterface $end): array
    {
        $usedBudget = $totals['purchaseCost'] + $totals['shippingCost'] + $totals['expenseCost'];

        return PurchaseMetricsCalculator::blocks($totals, $generalBudget, $usedBudget) + [
            'CollectionDuration' => [
                'startDate' => $start?->format('Y-m-d'),
                'endDate' => $end?->format('Y-m-d'),
                'days' => $start && $end ? (int) $start->diff($end)->days : 0,
            ],
            'totalItemCost' => $totals['purchaseCost'],
            'GeneralExpenses' => [
                'totalShippingCost' => $totals['shippingCost'],
                'totalExpenseCost' => $totals['expenseCost'],
                'totalGeneralExpenses' => $totals['shippingCost'] + $totals['expenseCost'],
            ],
            'Statistics' => ['orderCount' => $totals['orderCount'], 'itemCount' => $totals['itemCount'], 'modelCount' => $totals['modelCount']],
        ];
    }
}
