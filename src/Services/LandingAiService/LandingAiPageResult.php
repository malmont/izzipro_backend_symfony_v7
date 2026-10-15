<?php

namespace App\Services\LandingAiService;

final class LandingAiPageResult
{
    /**
     * Propositions sur les données du site, jamais appliquées par le backend (LandingAiContentProposals) :
     * contentChanges, groupChanges (retouche seulement) et limits (ce que l'assistant ne peut pas faire, et comment).
     *
     * @var array{contentChanges: list<array>, groupChanges: list<array>, limits: list<array>}
     */
    public array $proposals = ['contentChanges' => [], 'groupChanges' => [], 'limits' => []];

    /** Page système composée (mode page avec « system »), sinon null */
    public ?string $system = null;
    /** Accueil d'un site complet : sections Navbar et Footer, séparées des sections de la page, et charte déduite */
    public ?object $navbar = null;
    public ?object $footer = null;
    /** @var array<string, mixed>|null */
    public ?array $charter = null;

    /**
     * @param list<array{componentKey: string, dataType: ?string, composition: object}> $sections dans l'ordre de la page
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly array $sections,
        public readonly string $summary,
        public readonly array $warnings,
        public readonly LandingAiUsageStats $stats
    ) {
    }
}
