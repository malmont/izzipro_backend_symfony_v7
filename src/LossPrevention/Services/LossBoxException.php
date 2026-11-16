<?php

namespace App\LossPrevention\Services;

/**
 * Refus rendu à un boîtier (lot L3-a) : un code stable, un statut HTTP, des détails facultatifs. Le message est écrit pour le
 * journal technique du boîtier (personne ne le lit à l'écran) ; il ne porte jamais de donnée du site.
 */
class LossBoxException extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(public readonly string $error, string $message, public readonly int $status, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return ['error' => $this->error, 'code' => $this->error, 'message' => $this->getMessage()] + $this->details;
    }
}
