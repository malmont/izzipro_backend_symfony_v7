<?php

namespace App\Services\ReservationService;

/** Lot CMD-WEB-1 : refus d'un geste sur un rendez-vous de la boutique, avec son code stable */
class ShopBookingException extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return ['error' => $this->errorCode, 'code' => $this->errorCode, 'message' => $this->getMessage()] + ($this->field !== null ? ['field' => $this->field] : []);
    }
}
