<?php

namespace App\Services\LandingAiService;

use App\Entity\AiUsage;
use App\Repository\AiUsageRepository;
use App\Services\TenantEntityManagerProvider;

/**
 * Mémoire de l'assistant (19/10/2026) : pour une section ou une page désignée par sa clé de mémoire (memoryKey, choisie
 * par la console), les derniers échanges réussis (extrait de la demande, résumé de la réponse, issue indiquée par la
 * console) sont redonnés au modèle quand la console n'envoie pas elle-même d'historique. Rangée dans ai_usage (même
 * rétention : 90 jours), base du site courant. L'administrateur peut l'oublier pour une clé.
 */
final class LandingAiMemoryService
{
    public const LIMIT = 10;
    public const KEY_PATTERN = '/^[A-Za-z0-9:._-]{1,120}$/';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /**
     * Échanges à redonner au modèle, au format de l'historique de la console.
     *
     * @return list<array{prompt: string, summary: string, outcome: ?string}>
     */
    public function history(string $memoryKey): array
    {
        return array_map(fn (AiUsage $u) => [
            'prompt' => $u->getPromptExcerpt(),
            'summary' => (string) $u->getSummary(),
            'outcome' => $u->getOutcome(),
        ], $this->usages()->findMemory($memoryKey, self::LIMIT));
    }

    /** @return list<array{requestId: int, createdAt: string, mode: string, componentKey: string, prompt: string, summary: string, outcome: ?string}> */
    public function entries(string $memoryKey): array
    {
        return array_map(fn (AiUsage $u) => self::entry($u), $this->usages()->findMemory($memoryKey, self::LIMIT));
    }

    /**
     * Issue d'une demande réussie (appliquée, refusée, annulée), indiquée par la console.
     *
     * @throws LandingAiException 404 inconnue ou non réussie, 409 sans mémoire
     */
    public function setOutcome(int $requestId, string $outcome): array
    {
        $usage = $this->usages()->find($requestId);
        if ($usage === null || $usage->getStatus() !== AiUsage::STATUS_SUCCESS) {
            throw new LandingAiException(404, 'Demande introuvable', 'Cette demande est inconnue sur ce site, ou n\'a pas abouti.');
        }
        if ($usage->getMemoryKey() === null || $usage->getSummary() === null) {
            throw new LandingAiException(409, 'Demande sans mémoire', 'Cette demande n\'a pas de clé de mémoire (memoryKey) ou de résumé : son issue n\'est pas gardée.');
        }
        $usage->setOutcome($outcome);
        $this->emProvider->getEntityManager()->flush();

        return self::entry($usage);
    }

    /** Oublie les échanges d'une clé ; renvoie leur nombre */
    public function forget(string $memoryKey): int
    {
        return $this->usages()->forgetMemory($memoryKey);
    }

    private static function entry(AiUsage $u): array
    {
        return [
            'requestId' => (int) $u->getId(),
            'createdAt' => $u->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'mode' => $u->getMode(),
            'componentKey' => $u->getComponentKey(),
            'prompt' => $u->getPromptExcerpt(),
            'summary' => (string) $u->getSummary(),
            'outcome' => $u->getOutcome(),
        ];
    }

    private function usages(): AiUsageRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(AiUsage::class);
    }
}
