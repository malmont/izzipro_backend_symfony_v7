<?php

namespace App\Services;

/**
 * Réponse d'erreur de l'API Anthropic (statut HTTP ≠ 200), avec le délai Retry-After s'il est fourni.
 */
class AnthropicApiException extends \RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        private readonly ?string $errorType = null,
        private readonly ?int $retryAfter = null
    ) {
        parent::__construct(sprintf('API Anthropic : HTTP %d (%s)', $statusCode, $errorType ?? 'erreur inconnue'));
    }

    public function getStatusCode(): int { return $this->statusCode; }
    public function getErrorType(): ?string { return $this->errorType; }
    /** Secondes à attendre selon l'API, ou null */
    public function getRetryAfter(): ?int { return $this->retryAfter; }

    /** Erreur passagère : limite de débit (429), surcharge (529) ou erreur du serveur (5xx) */
    public function isTransient(): bool
    {
        return $this->statusCode === 429 || $this->statusCode >= 500;
    }
}
