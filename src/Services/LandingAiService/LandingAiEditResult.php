<?php

namespace App\Services\LandingAiService;

final class LandingAiEditResult
{
    /**
     * Propositions sur les données du site, jamais appliquées par le backend (LandingAiContentProposals) :
     * contentChanges, groupChanges (retouche seulement) et limits (ce que l'assistant ne peut pas faire, et comment).
     *
     * @var array{contentChanges: list<array>, groupChanges: list<array>, limits: list<array>}
     */
    public array $proposals = ['contentChanges' => [], 'groupChanges' => [], 'limits' => []];

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
