<?php

namespace App\Services\LandingAiService;

final class LandingAiEditResult
{
    /**
     * @param list<string> $warnings
     * @param list<string> $touchedBlockIds blocs visés par les opérations (les autres sont identiques)
     * @param list<mixed> $operations opérations retenues (pour l'évaluation)
     */
    public function __construct(
        public readonly object $composition,
        public readonly string $summary,
        public readonly array $warnings,
        public readonly array $touchedBlockIds,
        public readonly array $operations,
        public readonly LandingAiUsageStats $stats
    ) {
    }
}
