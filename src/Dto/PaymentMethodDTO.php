<?php
namespace App\Dto;

class PaymentMethodDTO
{
    /** Types propres au corps des routes de caisse (F9, 27/10/2026) : aucune ligne dans la table des moyens de paiement ni dans payments */
    public const GIFT_CARD = 4;
    public const STORE_CREDIT = 5;

    private $type;
    private $amount;
    /** Paiement par terminal Stripe (C4) : identifiant du paiement (POST /api/pos/terminal/payments) ou PaymentIntent, part carte seulement */
    private ?string $terminalPaymentId;
    /** Carte-cadeau ou avoir (F9) : code scanné ou saisi, types 4 et 5 seulement */
    private ?string $code;

    public function __construct(int $type, float $amount, ?string $terminalPaymentId = null, ?string $code = null)
    {
        $this->type = $type;
        $this->amount = $amount;
        $this->terminalPaymentId = $terminalPaymentId;
        $this->code = $code;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function isGiftCard(): bool
    {
        return $this->type === self::GIFT_CARD || $this->type === self::STORE_CREDIT;
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
