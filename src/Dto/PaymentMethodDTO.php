<?php
namespace App\Dto;

class PaymentMethodDTO
{
    private $type;
    private $amount;

    public function __construct(int $type, float $amount)
    {
        $this->type = $type;
        $this->amount = $amount;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }
}
