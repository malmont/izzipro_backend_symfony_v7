<?php

namespace App\Services\GestionService;

/**
 * Conflit d'état sur une route de gestion (lot G2, 09/11/2026) : 409 `{error: CODE, code, message}` (`ALREADY_CLOSED`,
 * `COMMANDE_CLOSED`, `COLLECTION_NOT_EMPTY`, `FOURNISSEUR_IN_USE`, `SHIPPING_COST_ALREADY_SET`…), rendu par
 * `EventListener/GestionValidationExceptionListener`. Contrat : `docs/gestion-mise-a-niveau.md` § 7.
 */
class GestionConflictException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    /** @return array<string, mixed> corps JSON de la réponse */
    public function toArray(): array
    {
        return ['error' => $this->errorCode, 'code' => $this->errorCode, 'message' => $this->getMessage()];
    }
}
