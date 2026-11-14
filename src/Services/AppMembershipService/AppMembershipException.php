<?php

namespace App\Services\AppMembershipService;

/**
 * Refus d'une route des applications à membres (lot M3) : un code stable que les fronts traduisent, un statut HTTP, et les
 * données à joindre à la réponse (`terms` pour `TERMS_VERSION_CHANGED`, par exemple).
 */
final class AppMembershipException extends \RuntimeException
{
    /** @param array<string, mixed> $extra champs ajoutés au corps de la réponse */
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    /** @return array<string, mixed> corps de la réponse d'erreur */
    public function body(): array
    {
        return ['error' => $this->errorCode, 'code' => $this->errorCode, 'message' => $this->getMessage()] + $this->extra;
    }
}
