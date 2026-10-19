<?php

namespace App\UseCase\LandingAiUseCase;

use App\Entity\AiUsage;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiMemoryService;

/**
 * Mémoire de l'assistant (19/10/2026) : GET / DELETE /api/landingpage-ai/memory?memoryKey= (échanges gardés pour une
 * section ou une page, ou oubli) et POST /api/landingpage-ai/usage/{id}/outcome (issue d'une proposition).
 */
class ManageLandingAiMemoryUseCase
{
    public function __construct(private readonly LandingAiMemoryService $memory)
    {
    }

    /** @throws LandingAiException 400 */
    public function entries(?string $memoryKey): array
    {
        return ['memoryKey' => $this->key($memoryKey), 'entries' => $this->memory->entries($this->key($memoryKey))];
    }

    /** @throws LandingAiException 400 */
    public function forget(?string $memoryKey): array
    {
        return ['memoryKey' => $this->key($memoryKey), 'forgotten' => $this->memory->forget($this->key($memoryKey))];
    }

    /** @throws LandingAiException 400, 404 ou 409 */
    public function outcome(int $requestId, mixed $body): array
    {
        $outcome = is_object($body) ? ($body->outcome ?? null) : null;
        if (!in_array($outcome, AiUsage::OUTCOMES, true)) {
            throw LandingAiException::badRequest('Corps attendu : { "outcome": "applied" | "rejected" | "undone" }.', [['path' => 'outcome', 'message' => 'valeur attendue : ' . implode(', ', AiUsage::OUTCOMES)]]);
        }

        return $this->memory->setOutcome($requestId, $outcome);
    }

    private function key(?string $memoryKey): string
    {
        if (!is_string($memoryKey) || !preg_match(LandingAiMemoryService::KEY_PATTERN, $memoryKey)) {
            throw LandingAiException::badRequest('Paramètre memoryKey invalide : 1 à 120 caractères (lettres, chiffres, : . _ -).', [['path' => 'memoryKey', 'message' => 'clé de mémoire attendue']]);
        }

        return $memoryKey;
    }
}
