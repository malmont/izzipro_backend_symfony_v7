<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\OrderTax;
use App\Repository\TaxRepository;
use Doctrine\ORM\EntityManagerInterface;

class CalculateTaxesUseCase
{
    private $em;
    private $taxRepository;

    public function __construct(EntityManagerInterface $em, TaxRepository $taxRepository)
    {
        $this->em = $em;
        $this->taxRepository = $taxRepository;
    }

    public function execute(Order $order, float $subtotal): float
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

            $this->em->persist($orderTax);
        }

        return $totalTax;
    }
}
