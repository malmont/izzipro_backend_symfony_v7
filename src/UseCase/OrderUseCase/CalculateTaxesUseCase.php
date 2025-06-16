<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Services\OrderService\TaxCalculationService;
use App\Services\TenantEntityManagerProvider; 

class CalculateTaxesUseCase
{
    // MODIFICATION 1 : Le service ne dépend plus que du provider et du service de calcul
    private TenantEntityManagerProvider $emProvider;
    private TaxCalculationService $taxCalculationService;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        TaxCalculationService $taxCalculationService
    ) {
        $this->emProvider = $emProvider;
        $this->taxCalculationService = $taxCalculationService;
    }

    public function execute(Order $order, float $subtotal): float
    {
        $totalTax = $this->taxCalculationService->calculateTaxes($order, $subtotal);
        return $totalTax;
    }
}