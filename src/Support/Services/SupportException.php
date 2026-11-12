<?php

namespace App\Support\Services;

/** Erreur métier du module Assistance : code stable (traduit par les applications), statut HTTP, détails (champs en erreur) */
class SupportException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 409, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    /** @param array<string, string> $fields champ => code */
    public static function validation(array $fields): self
    {
        return new self('VALIDATION_FAILED', 'Demande d\'assistance invalide : ' . implode(', ', array_map(fn ($k, $v) => "$k ($v)", array_keys($fields), $fields)), 422, ['fields' => $fields]);
    }
}
