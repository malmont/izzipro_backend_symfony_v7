<?php
namespace App\UseCase\OrderUseCase;


use App\Entity\Order;

class CalculateTotalAmountUseCase
{
    public function execute(float $subtotal, float $totalTax): float
    {
        return $subtotal + $totalTax;
    }
}
