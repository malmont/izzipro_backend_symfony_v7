<?php

namespace App\Services\LandingAiService;

/**
 * Consommation d'une demande à l'assistant (tous essais cumulés).
 */
final class LandingAiUsageStats
{
    public int $attempts = 0;
    public int $inputTokens = 0;
    public int $outputTokens = 0;
    public int $cacheReadTokens = 0;
    /** Jetons écrits dans le cache (déjà compris dans inputTokens), facturés 1,25 fois (5 min) ou 2 fois (1 h) */
    public int $cacheWriteTokens = 0;
    public int $durationMs = 0;

    public function __construct(public string $model)
    {
    }

    /** Ajoute l'usage d'une réponse de l'API Messages */
    public function add(object|array|null $usage): void
    {
        $usage = (array) $usage;
        $this->inputTokens += (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $this->outputTokens += (int) ($usage['output_tokens'] ?? 0);
        $this->cacheReadTokens += (int) ($usage['cache_read_input_tokens'] ?? 0);
        $this->cacheWriteTokens += (int) ($usage['cache_creation_input_tokens'] ?? 0);
    }

    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'attempts' => $this->attempts,
            'inputTokens' => $this->inputTokens,
            'outputTokens' => $this->outputTokens,
            'cacheReadTokens' => $this->cacheReadTokens,
            'cacheWriteTokens' => $this->cacheWriteTokens,
            'durationMs' => $this->durationMs,
        ];
    }
}
