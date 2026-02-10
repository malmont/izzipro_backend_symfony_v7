<?php

namespace App\Services\OrderService;

use App\Entity\Order;
use App\Entity\OrderTax;
use App\Entity\Tax;
use App\Services\TenantEntityManagerProvider;

class TaxCalculationService
{
    /**
     * The service now only depends on the provider.
     */
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function calculateTaxes(Order $order, float $subtotal, bool $persist = true): float
    {
        // Get the tenant-specific EntityManager here
        $em = $this->emProvider->getEntityManager();
        $taxRepository = $em->getRepository(Tax::class);

        $totalTax = 0;
        $taxes = $taxRepository->findAll();

        foreach ($taxes as $tax) {
            $taxAmount = $subtotal * $tax->getRate();
            $totalTax += $taxAmount;

            $orderTax = new OrderTax();
            $orderTax->setOrderTax($order);
            $orderTax->setTax($tax);
            $orderTax->setAmount($taxAmount);
            $order->addOrderTax($orderTax);

            if ($persist) {
                $em->persist($orderTax);
            }
        }
        return $totalTax;
    }
}
