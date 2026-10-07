<?php

namespace App\Services\LandingAiService;

final class LandingAiCreateResult
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
     */
    public function __construct(
        public readonly object $composition,
        public readonly ?string $dataType,
        public readonly string $summary,
        public readonly array $warnings,
        public readonly LandingAiUsageStats $stats
    ) {
    }
}
