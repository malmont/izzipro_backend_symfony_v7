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

    public function __construct(TenantEntityManagerProvider $emProvider, private readonly ?\App\Services\CaisseService\StoreClock $clock = null)
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
        $storeNow = new \DateTimeImmutable('now', $this->clock?->timezone() ?? new \DateTimeZone('UTC'));

        return [
            'stock_value_current_month' => [
                [
                    'month' => $currentDate->format('F Y'),
                    'stock_value' => $totalValue,
                    // B1 (11/11/2026, additif) : en cents, calculé par variante (stock × prix de la variante, sinon de l'article ; article sans
                    // variante : sa quantité × son prix) ; diffère de stock_value, qui ne lit que la quantité et le prix de l'article
                    'stock_value_cents' => $this->stockValueCents(),
                    'month_number' => (int) $storeNow->format('n'),
                    'year' => (int) $storeNow->format('Y'),
                ]
            ]
        ];
    }

    /** Valeur du stock en cents, par variante (B1) ; articles sans variante : quantité × prix de l'article ; publique pour S1 */
    public function stockValueCents(): int
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();

        return (int) round((float) $conn->fetchOne(
            'SELECT COALESCE((SELECT SUM(pv.stock_quantity * COALESCE(pv.price, p.price)) FROM product_variant pv JOIN product p ON p.id = pv.product_id'
            . ' WHERE pv.stock_quantity > 0 AND COALESCE(pv.price, p.price) IS NOT NULL), 0)'
            . ' + COALESCE((SELECT SUM(p.quantity * p.price) FROM product p WHERE p.quantity > 0 AND p.price IS NOT NULL AND NOT EXISTS (SELECT 1 FROM product_variant v WHERE v.product_id = p.id)), 0)'
        ));
    }
}
