<?php

namespace App\Services\AppKeyService;

/**
 * Refus lié aux clés d'activation, rendu {error, message, errors?} avec son code HTTP : APP_KEY_MISSING (400),
 * APP_KEY_INVALID (404 : inconnue, révoquée ou expirée), APP_KEY_WRONG_APP (403), APP_KEY_NOT_FOUND (404),
 * VALIDATION_FAILED (422).
 */
class AppKeyException extends \RuntimeException
{
    /** @param list<array{path: string, message: string}> $errors */
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status, public readonly array $errors = [])
    {
        parent::__construct($message);
    }

    public function toBody(): array
    {
        return array_filter(['error' => $this->errorCode, 'message' => $this->getMessage(), 'errors' => $this->errors]);
    }
}
