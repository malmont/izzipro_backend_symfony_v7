<?php

namespace App\Services\LandingAiService;

/**
 * Échec de l'assistant IA, porteur du statut HTTP et du format d'erreur commun { error, message, errors? }.
 */
class LandingAiException extends \RuntimeException
{
    /**
     * @param list<array{path: string, message: string}> $errors
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly string $error,
        string $message,
        private readonly array $errors = [],
        private readonly array $headers = [],
        private readonly ?LandingAiUsageStats $stats = null,
        private readonly array $extra = []
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $message, array $errors = []): self
    {
        return new self(400, 'Requête invalide', $message, $errors);
    }

    public function getStatusCode(): int { return $this->statusCode; }
    public function getError(): string { return $this->error; }
    /** @return list<array{path: string, message: string}> */
    public function getErrors(): array { return $this->errors; }
    /** @return array<string, string> */
    public function getHeaders(): array { return $this->headers; }
    public function getStats(): ?LandingAiUsageStats { return $this->stats; }

    public function toArray(): array
    {
        return array_filter([
            'error' => $this->error,
            'message' => $this->getMessage(),
            'errors' => $this->errors ?: null,
        ], fn ($v) => $v !== null) + $this->extra;
    }
}
