<?php

namespace App\Services\GestionService;

/**
 * Entrée refusée par une route de gestion (lot G1, 09/11/2026) : 422 `VALIDATION_FAILED` avec, par champ, un code stable
 * (`MUST_BE_INTEGER_CENTS`, `REQUIRED`, `INVALID_DATE`…) que l'application traduit. Contrat : `docs/gestion-mise-a-niveau.md`.
 */
class GestionValidationException extends \RuntimeException
{
    public const CODE = 'VALIDATION_FAILED';

    /** @param array<string, string> $fields champ => code de la raison */
    public function __construct(public readonly array $fields, string $message = 'Entrée invalide')
    {
        parent::__construct($message . ' : ' . implode(', ', array_map(fn ($f, $c) => "$f ($c)", array_keys($fields), $fields)));
    }

    /** @return array<string, mixed> corps JSON de la réponse 422 */
    public function toArray(): array
    {
        return ['error' => self::CODE, 'code' => self::CODE, 'message' => $this->getMessage(), 'fields' => $this->fields];
    }
}
