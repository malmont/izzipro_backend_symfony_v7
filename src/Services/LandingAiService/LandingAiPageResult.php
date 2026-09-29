<?php

namespace App\Services\LandingAiService;

final class LandingAiPageResult
{
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
