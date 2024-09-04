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
            $orderTax->setOrderTax($order);  // Associer la taxe à l'ordre
            $orderTax->setTax($tax);
            $orderTax->setAmount($taxAmount);

            // Ajouter à l'ordre
            $order->addOrderTax($orderTax);

            // Persister chaque instance de OrderTax
            $this->em->persist($orderTax);
        }

        // Ici, le flush n'est pas encore nécessaire, il sera probablement fait ailleurs
        return $totalTax;
    }
}
