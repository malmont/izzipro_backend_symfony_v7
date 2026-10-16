<?php

namespace App\MemoiresVivantes\Exception;

/** Refus métier rendu {error, message, …} avec son code HTTP (paiements, tarifs, impression) */
class ApiErrorException extends \RuntimeException
{
    /** @param array<string, mixed> $extra champs ajoutés au corps */
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public function toBody(): array
    {
        return ['error' => $this->errorCode, 'message' => $this->getMessage()] + $this->extra;
    }
}
