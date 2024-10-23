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

    public function getStockValueForCurrentMonth(): float
    {
        $date = new DateTime('last day of this month');
        return $this->calculateStockValueForDate($date);
    }

    public function getStockValueForLastMonth(): float
    {
        $date = new DateTime('last day of last month');
        return $this->calculateStockValueForDate($date);
    }

    public function getStockValueForTwoMonthsAgo(): float
    {
        $date = new DateTime('last day of -2 month');
        return $this->calculateStockValueForDate($date);
    }

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
}