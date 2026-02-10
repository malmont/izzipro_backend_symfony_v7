<?php

namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Services\OrderService\TaxCalculationService;
use App\Services\TenantEntityManagerProvider;

class CalculateTaxesUseCase
{
    private TenantEntityManagerProvider $emProvider;
    private TaxCalculationService $taxCalculationService;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        TaxCalculationService $taxCalculationService
    ) {
        $this->emProvider = $emProvider;
        $this->taxCalculationService = $taxCalculationService;
    }

    public function execute(Order $order, float $subtotal, bool $persist = true): float
    {
        $totalTax = $this->taxCalculationService->calculateTaxes($order, $subtotal, $persist);
        return $totalTax;
    }
}
