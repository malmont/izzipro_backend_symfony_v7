<?php
namespace App\Dto;

class PaymentMethodDTO
{
    private $type;
    private $amount;
    /** Paiement par terminal Stripe (C4) : identifiant du paiement (POST /api/pos/terminal/payments) ou PaymentIntent, part carte seulement */
    private ?string $terminalPaymentId;

    public function __construct(int $type, float $amount, ?string $terminalPaymentId = null)
    {
        $this->type = $type;
        $this->amount = $amount;
        $this->terminalPaymentId = $terminalPaymentId;
    }

    public function getTerminalPaymentId(): ?string
    {
        return $this->terminalPaymentId;
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
