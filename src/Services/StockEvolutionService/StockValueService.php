<?php

namespace App\Services\StockEvolutionService;

use App\Repository\InventoryMovementsRepository;
use App\Repository\ProductRepository;
use DateTime;

class StockValueService
{
    private $productRepository;
    private $inventoryMovementsRepository;

    public function __construct(ProductRepository $productRepository, InventoryMovementsRepository $inventoryMovementsRepository)
    {
        $this->productRepository = $productRepository;
        $this->inventoryMovementsRepository = $inventoryMovementsRepository;
    }

    // Renvoie la valeur de stock pour le mois actuel au format objet
    public function getStockValueForCurrentMonth(): array
    {
        $date = new DateTime('last day of this month');
        $totalValue = $this->calculateStockValueForDate($date);
        return $this->transformToStockValueList($date, $totalValue);
    }

    // Renvoie la valeur de stock pour le mois précédent au format objet
    public function getStockValueForLastMonth(): array
    {
        $date = new DateTime('last day of last month');
        $totalValue = $this->calculateStockValueForDate($date);
        return $this->transformToStockValueList($date, $totalValue);
    }

    // Renvoie la valeur de stock pour il y a deux mois au format objet
    public function getStockValueForTwoMonthsAgo(): array
    {
        $date = new DateTime('last day of -2 month');
        $totalValue = $this->calculateStockValueForDate($date);
        return $this->transformToStockValueList($date, $totalValue);
    }

    // Calcule la valeur de stock pour une date donnée
    private function calculateStockValueForDate(DateTime $date): float
    {
        $products = $this->productRepository->findAll();
        $totalValue = 0.0;

        foreach ($products as $product) {
            $quantityAtDate = $this->inventoryMovementsRepository->getStockQuantityAtDate($product, $date);
            if ($product->getPurchasePrice() !== null && $product->getCoefficientMultiplier() !== null) {
                $price = $product->getPurchasePrice() * $product->getCoefficientMultiplier();
                $totalValue += $price * $quantityAtDate;
            }
        }

        return $totalValue;
    }

    // Transforme la valeur en une structure d'objet
    private function transformToStockValueList(DateTime $date, float $totalValue): array
    {
        return [
            [
                'month' => $date->format('F Y'),
                'stock_value' => $totalValue,
            ]
        ];
    }

         /**
     * Renvoie la valeur de stock formatée pour le mois actuel.
     *
     * @return array
     */
    public function getStockValueCurrentMonth(): array
    {
        $currentDate = new DateTime('last day of this month');
        $totalValue = $this->productRepository->calculateCurrentStockValue();

        return [
            'stock_value_current_month' => [
                [
                    'month' => $currentDate->format('F Y'),
                    'stock_value' => $totalValue,
                ]
            ]
        ];
    }
}
