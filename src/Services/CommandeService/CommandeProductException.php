<?php

namespace App\Services\CommandeService;

/** Refus du rattachement ou du détachement d'un produit (09/11/2026) : code stable, statut HTTP, détails par champ, contexte. */
class CommandeProductException extends \RuntimeException
{
    /** @param list<array{path: string, message: string}> $errors @param array<string, mixed> $context */
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status, public readonly array $errors = [], public readonly array $context = [])
    {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function toBody(): array
    {
        return array_filter(['error' => $this->errorCode, 'code' => $this->errorCode, 'message' => $this->getMessage(), 'errors' => $this->errors ?: null, 'context' => $this->context ?: null], fn ($v) => $v !== null);
    }
}
