<?php

namespace App\Services\LandingAiService;

final class LandingAiCreateResult
{
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
