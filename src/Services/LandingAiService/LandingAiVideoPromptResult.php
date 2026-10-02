<?php

namespace App\Services\LandingAiService;

/** Prompt de vidéo pour une scène au défilement : prompt(s) en anglais, étapes et notes dans la langue de l'administrateur */
final class LandingAiVideoPromptResult
{
    /**
     * @param list<array{at: int|float, title: string, text: string}> $steps
     * @param list<string> $notes
     */
    public function __construct(
        public readonly string $prompt,
        public readonly ?string $promptMobile,
        public readonly array $steps,
        public readonly array $notes,
        public readonly LandingAiUsageStats $stats
    ) {
    }
}
