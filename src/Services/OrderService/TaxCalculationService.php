<?php
namespace App\Services\OrderService;

use App\Entity\Order;
use App\Entity\OrderTax;
use App\Repository\TaxRepository;
use Doctrine\ORM\EntityManagerInterface;

class TaxCalculationService
{
    private $taxRepository;
    private $em;

    public function __construct(EntityManagerInterface $em, TaxRepository $taxRepository)
    {
        $this->em = $em;
        $this->taxRepository = $taxRepository;
    }

    public function calculateTaxes(Order $order, float $subtotal): float
    {
        $totalTax = 0;
        $taxes = $this->taxRepository->findAll();

        foreach ($taxes as $tax) {
            $taxAmount = $subtotal * $tax->getRate();
            $totalTax += $taxAmount;

            $orderTax = new OrderTax();
            $orderTax->setOrderTax($order); 
            $orderTax->setTax($tax);
            $orderTax->setAmount($taxAmount);
            $order->addOrderTax($orderTax);
            $this->em->persist($orderTax);
        }

        return $totalTax;
    }
}
