<?php

namespace App\Services\CaisseService;

/**
 * Refus lié aux postes et sessions de caisse ; rendu en JSON {error, message} avec son code HTTP (docs/caisse.md).
 * Codes : CASH_REGISTER_NOT_FOUND (404), CASH_REGISTER_REQUIRED (400), CASH_REGISTER_CLOSED (400 sur les routes de
 * caisse, 409 sur une vente), CAISSE_ALREADY_OPEN (400), CASH_REGISTER_OPEN (409), INSUFFICIENT_FUNDS (400),
 * INVALID_AMOUNT (400), VALIDATION_FAILED (422).
 */
class CashRegisterException extends \RuntimeException
{
    /** @param list<array{path: string, message: string}> $errors */
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status, public readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /** @return array<string, mixed> corps de la réponse */
    public function toBody(): array
    {
        return array_filter(['error' => $this->errorCode, 'message' => $this->getMessage(), 'errors' => $this->errors]);
    }
}
