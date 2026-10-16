<?php

namespace App\MemoiresVivantes\Exception;

/** Quota IA du jour atteint : 429 {error: QUOTA_EXCEEDED, message, quota: {kind, limit, used, resetsAt}} + Retry-After */
class AiQuotaExceededException extends \RuntimeException
{
    public function __construct(public readonly string $kind, public readonly int $limit, public readonly int $used, public readonly \DateTimeImmutable $resetsAt)
    {
        $what = $kind === 'transcription' ? ($limit > 1 ? 'transcriptions' : 'transcription') : ($limit > 1 ? 'améliorations par l\'IA' : 'amélioration par l\'IA');
        parent::__construct(sprintf('Vous avez atteint votre limite de %d %s par jour. Vous pourrez recommencer le %s.', $limit, $what, $resetsAt->format('d/m/Y à H:i')));
    }

    public function toBody(): array
    {
        return ['error' => 'QUOTA_EXCEEDED', 'message' => $this->getMessage(), 'quota' => ['kind' => $this->kind, 'limit' => $this->limit, 'used' => $this->used, 'resetsAt' => $this->resetsAt->format(\DateTimeInterface::ATOM)]];
    }

    /** Secondes jusqu'à la remise à zéro (en-tête Retry-After) */
    public function retryAfterSeconds(): int
    {
        return max(1, $this->resetsAt->getTimestamp() - time());
    }
}
