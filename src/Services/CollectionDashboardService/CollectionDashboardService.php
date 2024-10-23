<?php
namespace App\Services\CollectionDashboardService;

use App\Dto\DashboardCollectionDTO;
use App\Entity\Collections;

class CollectionDashboardService
{
    public function calculateDashboardMetrics(Collections $collection): DashboardCollectionDTO
    {
        $commandes = $collection->getCommandes();
        $totalItemCost = 0;
        $totalShippingCost = 0;
        $totalExpenseCost = 0;
        $totalQuantity = 0;
        $totalMultiplier = 0;
        $itemCount = 0;
        $countProduct = 0;
        $stockValue = 0;

        foreach ($commandes as $commande) {
            $products = $commande->getProducts();
            $countProduct += count($products);
            foreach ($products as $product) {
                $totalItemCost += $product->getPrice() * $product->getQuantity();
                $totalQuantity += $product->getQuantity();
                $totalMultiplier += $product->getCoefficientMultiplier() * $product->getQuantity();
                $stockValue += $product->getPrice() * $product->getQuantity() * $product->getCoefficientMultiplier();
                $itemCount += $product->getQuantity();
            }
            if ($commande->getFraisDePort()) {
                $totalShippingCost += $commande->getFraisDePort()->getPrice();
            }
        }

        $averageMultiplier = $totalQuantity > 0 ? $totalMultiplier / $totalQuantity : 0;

        foreach ($collection->getNoteDeFrais() as $note) {
            $totalExpenseCost += $note->getMontant();
        }

        $generalBudget = $collection->getBudgetCollection();
        $usedBudget = $totalItemCost + $totalShippingCost + $totalExpenseCost;
        $remainingBudget = $generalBudget - $usedBudget;

        $startDate = $collection->getStartDateCollection();
        $endDate = $collection->getEndDateCollection();
        $durationDays = $startDate->diff($endDate)->days;

        $data = [
            'averageMultiplier' => $averageMultiplier,
            'BudgetGeneral' => [
                'generalBudget' => $generalBudget,
                'usedBudget' => $usedBudget,
                'remainingBudget' => $remainingBudget,
            ],
            'CollectionDuration' => [
                'startDate' => $startDate->format('Y-m-d'),
                'endDate' => $endDate->format('Y-m-d'),
                'days' => $durationDays,
            ],
            'totalItemCost' => $totalItemCost,
            'GeneralExpenses' => [
                'totalShippingCost' => $totalShippingCost,
                'totalExpenseCost' => $totalExpenseCost,
                'totalGeneralExpenses' => ($totalShippingCost + $totalExpenseCost),
            ],
            'Statistics' => [
                'orderCount' => count($commandes),
                'itemCount' => $itemCount,
                'modelCount' => $countProduct,
            ],
            'ValeurStock' => [
                'stockValue' => $stockValue,
                'marge' => ($stockValue - $usedBudget),
            ],
            'TauxMarge' => [
            'tauxMarge' => ($stockValue > 0) ? (($stockValue - $usedBudget) / $stockValue) * 100 : 0,
            'tauxMarque' => ($usedBudget > 0) ? (($stockValue - $usedBudget) / $usedBudget) * 100 : 0,
            ],
        ];

        return DashboardCollectionDTO::fromArray($data);
    }
}
