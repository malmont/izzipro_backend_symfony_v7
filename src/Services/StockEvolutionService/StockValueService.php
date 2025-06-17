<?php
namespace App\Services\StockEvolutionService;

use App\Entity\Product;
use App\Entity\InventoryMovements;
use App\Repository\ProductRepository;
use App\Repository\InventoryMovementsRepository;
use App\Services\TenantEntityManagerProvider;
use DateTime;

class StockValueService
{
    /**
     * MODIFICATION 1 : Le service ne dépend plus que du provider.
     */
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    /**
     * MODIFICATION 2 : On crée des méthodes privées pour récupérer les repositories du tenant.
     */
    private function getProductRepository(): ProductRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(Product::class);
    }

    private function getInventoryMovementsRepository(): InventoryMovementsRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(InventoryMovements::class);
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
        // MODIFICATION 3 : On utilise nos nouvelles méthodes privées
        $productRepository = $this->getProductRepository();
        $inventoryMovementsRepository = $this->getInventoryMovementsRepository();

        $products = $productRepository->findAll();
        $totalValue = 0.0;

        foreach ($products as $product) {
            $quantityAtDate = $inventoryMovementsRepository->getStockQuantityAtDate($product, $date);
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
     */
    public function getStockValueCurrentMonth(): array
    {
        $productRepository = $this->getProductRepository();
        $currentDate = new DateTime('last day of this month');
        $totalValue = $productRepository->calculateCurrentStockValue();

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