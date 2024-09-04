<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Services\OrderService\TaxCalculationService;
use Doctrine\ORM\EntityManagerInterface;

class CalculateTaxesUseCase
{
    private $em;
    private $taxCalculationService;

    public function __construct(EntityManagerInterface $em, TaxCalculationService $taxCalculationService)
    {
        $this->em = $em;
        $this->taxCalculationService = $taxCalculationService;
    }

    public function execute(Order $order, float $subtotal): float
    {
        $totalTax = $this->taxCalculationService->calculateTaxes($order, $subtotal);

        // Persist the changes to the database       
        return $totalTax;
    }
}
