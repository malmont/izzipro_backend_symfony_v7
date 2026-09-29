<?php

namespace App\Services\LandingConfigService;

/**
 * Erreur de la synchronisation de configuration, renvoyée telle quelle : { error, message, errors? }.
 */
final class LandingConfigException extends \RuntimeException
{
    /**
     * @param list<array<string, mixed>> $errors
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly string $error,
        string $message,
        private readonly array $errors = [],
        private readonly array $extra = []
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int { return $this->statusCode; }
    public function getError(): string { return $this->error; }
    /** @return list<array<string, mixed>> */
    public function getErrors(): array { return $this->errors; }

    public function toArray(): array
    {
        return array_filter(['error' => $this->error, 'message' => $this->getMessage(), 'errors' => $this->errors ?: null], fn ($v) => $v !== null) + $this->extra;
    }
}
