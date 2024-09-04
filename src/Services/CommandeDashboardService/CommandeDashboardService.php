<?php
namespace App\Services\CommandeDashboardService;

use App\Dto\DashboardCommandeDTO;
use App\Entity\Commande;

class CommandeDashboardService
{
    public function calculateCommandeMetrics(Commande $commande): DashboardCommandeDTO
    {
        $totalMultiplier = 0;
        $totalItems = 0;
        $totalItemCost = 0;
        $modelCount = 0;

        foreach ($commande->getProducts() as $product) {
            $totalMultiplier += $product->getCoefficientMultiplier() * $product->getQuantity();
            $totalItems += $product->getQuantity();
            $totalItemCost += $product->getPrice() * $product->getQuantity();
            $modelCount++;
        }

        $averageMultiplier = $totalItems ? $totalMultiplier / $totalItems : 0;

        // Calcul du budget
        $generalBudget = $commande->getBudget();
        $usedBudget = $totalItemCost;

        if ($commande->getFraisDePort()) {
            $usedBudget += $commande->getFraisDePort()->getPrice();
        }

        $remainingBudget = $generalBudget - $usedBudget;

        // Calcul des revenus
        $stockValue = $totalItemCost * $averageMultiplier;

        // Calcul de la marge
        $marge = $stockValue - $usedBudget;

        // Transporteur
        $shippingCost = 0;
        $transporteur = null;
        if ($commande->getFraisDePort()) {
            $shippingCost = $commande->getFraisDePort()->getPrice();
            $transporteur = $commande->getFraisDePort()->getTransporteur() ? [
                'name' => $commande->getFraisDePort()->getTransporteur()->getName(),
                'logo' => $commande->getFraisDePort()->getTransporteur()->getLogo(),
                'contact' => $commande->getFraisDePort()->getTransporteur()->getContact(),
            ] : null;
        }

        // Construction des données pour le DTO
        $data = [
            'averageMultiplier' => $averageMultiplier,
            'BudgetGeneral' => [
                'generalBudget' => $generalBudget,
                'usedBudget' => $usedBudget,
                'remainingBudget' => $remainingBudget,
            ],
            'totalItemCost' => $totalItemCost,
            'totalFraisDePort' => $shippingCost,
            'Statistics' => [
                'itemCount' => $totalItems,
                'modelCount' => $modelCount,
            ],
            'ValeurStock' => [
                'stockValue' => $stockValue,
                'marge' => $marge,
            ],
            'TauxMarge' => [
                'tauxMarge' => ($stockValue > 0) ? (($stockValue - $usedBudget) / $stockValue) * 100 : 0,
                'tauxMarque' => (($stockValue - $usedBudget) / $usedBudget) * 100,
            ],
            'Transporteur' => $transporteur,
        ];

        return DashboardCommandeDTO::fromArray($data);
    }
}
