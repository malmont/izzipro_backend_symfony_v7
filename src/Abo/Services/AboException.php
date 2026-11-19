<?php

namespace App\Abo\Services;

/**
 * Refus d'un geste de l'abonnement universel (lot ABO-0, 18/11/2026) : statut HTTP, code stable que les applications traduisent
 * (BENEFIT_NAME_REQUIRED, PLAN_BENEFIT_DUPLICATE, SUBSCRIPTION_NOT_FOUND…), champ en cause. Le message est écrit pour l'administrateur.
 */
final class AboException extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    /** @return array{error: string, code: string, message: string, field?: string} */
    public function toArray(): array
    {
        return ['error' => $this->errorCode, 'code' => $this->errorCode, 'message' => $this->getMessage()] + ($this->field !== null ? ['field' => $this->field] : []);
    }
}
