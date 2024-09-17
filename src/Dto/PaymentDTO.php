<?php

namespace App\Dto;

class PaymentDTO
{
    private $id;
    private $amount;
    private $paymentDate;
    private $orderReference;
    private $paymentMethod;
    private $paymentStatus;

    public function __construct($id, $amount, $paymentDate, $orderReference, $paymentMethod, $paymentStatus)
    {
        $this->id = $id;
        $this->amount = $amount;
        $this->paymentDate = $paymentDate;
        $this->orderReference = $orderReference;
        $this->paymentMethod = $paymentMethod;
        $this->paymentStatus = $paymentStatus;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'paymentDate' => $this->paymentDate,
            'orderReference' => $this->orderReference,
            'paymentMethod' => $this->paymentMethod,
            'paymentStatus' => $this->paymentStatus,
        ];
    }
}
