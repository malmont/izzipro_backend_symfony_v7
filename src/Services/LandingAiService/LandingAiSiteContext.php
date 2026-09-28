<?php

namespace App\Services\LandingAiService;

use App\Services\LandingPageSettingsService\LandingPageSettingsService;
use App\Services\LandingPageSettingsService\ReglableCompositionValidator;

/**
 * Éléments du site courant (tenant) utiles à l'assistant : palette, polices et médias déjà utilisés dans les
 * compositions enregistrées. Lecture seule.
 */
final class LandingAiSiteContext
{
    private const MAX_COLORS = 16;
    private const MAX_FONTS = 6;

    public function __construct(
        private readonly LandingPageSettingsService $settings,
        private readonly ReglableCompositionValidator $validator,
        private readonly CompositionInspector $inspector
    ) {
    }

    /** @return list<object> compositions enregistrées du tenant (sections, navbar, footer, modèles) */
    public function storedCompositions(): array
    {
        $setting = $this->settings->findOrCreateSettings();
        $raw = $this->settings->getRawConfiguration($setting);
        if ($raw === null) {
            return [];
        }

        return array_values(array_filter($this->validator->compositions(json_decode($raw, false)), 'is_object'));
    }

    /**
     * @param list<object> $compositions
     * @return array{colors: array<string, int>, fonts: array<string, int>}
     */
    public function palette(array $compositions): array
    {
        $colors = $fonts = [];
        foreach ($compositions as $composition) {
            foreach ($this->inspector->colors($composition) as $color => $n) {
                $colors[$color] = ($colors[$color] ?? 0) + $n;
            }
            foreach ($this->inspector->fonts($composition) as $font => $n) {
                $fonts[$font] = ($fonts[$font] ?? 0) + $n;
            }
        }
        arsort($colors);
        arsort($fonts);

        return [
            'colors' => array_slice($colors, 0, self::MAX_COLORS, true),
            'fonts' => array_slice($fonts, 0, self::MAX_FONTS, true),
        ];
    }

    /**
     * Médias autorisés : ceux des compositions données et ceux fournis dans la demande.
     *
     * @param list<object> $compositions
     * @param list<array{kind?: string, url?: ?string, mediaKey?: ?string, label?: ?string}> $requestMedia
     * @return list<string>
     */
    public function allowedMedia(array $compositions, array $requestMedia = []): array
    {
        $allowed = [];
        foreach ($compositions as $composition) {
            foreach ($this->inspector->mediaReferences($composition) as $ref) {
                $allowed[$ref['value']] = true;
            }
        }
        foreach ($requestMedia as $media) {
            foreach (['url', 'mediaKey'] as $key) {
                if (is_string($media[$key] ?? null) && $media[$key] !== '') {
                    $allowed[$media[$key]] = true;
                }
            }
        }

        return array_keys($allowed);
    }
}
