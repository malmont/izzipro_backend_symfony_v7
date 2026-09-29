<?php

namespace App\Services\LandingAiService;

use App\Services\LandingConfigService\LandingConfigStore;

/**
 * Catalogue du frontend (config/landingpage/landingpage-ia-catalogue.json, copie exacte de npm run ia:catalogue) :
 * pour chaque famille (componentKey), les types de blocs autorisés, les champs liables et les modèles de référence.
 */
final class LandingAiCatalogue
{
    private const REFERENCE_MAX_CHARS = 6000;

    private ?array $catalogue = null;
    /** Fichier chargé dans $catalogue */
    private ?string $loadedFrom = null;

    public function __construct(private readonly LandingConfigStore $configStore)
    {
    }

    public function hasFamily(string $componentKey): bool
    {
        return $this->family($componentKey) !== null;
    }

    /** Famille au format du catalogue (tableau associatif), ou null */
    public function family(string $componentKey): ?array
    {
        foreach ($this->load()['families'] ?? [] as $family) {
            if (($family['componentKey'] ?? null) === $componentKey) {
                return $family;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function componentKeys(): array
    {
        return array_values(array_map(fn ($f) => (string) $f['componentKey'], $this->load()['families'] ?? []));
    }

    /** @return list<string> types de blocs autorisés pour la famille */
    public function tools(string $componentKey): array
    {
        return array_values($this->family($componentKey)['tools'] ?? []);
    }

    /** Modèle par identifiant, toutes familles confondues : [famille, modèle] ou null */
    public function preset(string $presetId): ?array
    {
        foreach ($this->load()['families'] ?? [] as $family) {
            foreach ($family['presets'] ?? [] as $preset) {
                if (($preset['id'] ?? null) === $presetId) {
                    return [$family, $preset];
                }
            }
        }

        return null;
    }

    /**
     * Modèles d'exemple pour une demande : le modèle d'origine (presetId) d'abord, puis ceux dont le nom ou la
     * description partagent le plus de mots avec la demande.
     *
     * @return list<array> modèles (id, name, description, composition)
     */
    public function examples(string $componentKey, ?string $originPresetId, string $prompt, int $count = 3): array
    {
        $presets = $this->family($componentKey)['presets'] ?? [];
        $words = $this->words($prompt);
        $scored = [];
        foreach ($presets as $i => $preset) {
            $score = count(array_intersect($words, $this->words(($preset['name'] ?? '') . ' ' . ($preset['description'] ?? ''))));
            if (($preset['id'] ?? null) === $originPresetId) {
                $score = PHP_INT_MAX;
            }
            $scored[] = [$score, -$i, $preset];
        }
        usort($scored, fn ($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        return array_map(fn ($s) => $s[2], array_slice($scored, 0, $count));
    }

    /** Entrée de famille sans les compositions des modèles (le contexte fixe envoyé au modèle) */
    public function familySummary(string $componentKey): array
    {
        $family = $this->family($componentKey) ?? [];
        $family['presets'] = array_map(
            fn ($p) => ['id' => $p['id'] ?? '', 'name' => $p['name'] ?? '', 'description' => $p['description'] ?? ''],
            $family['presets'] ?? []
        );

        return $family;
    }

    /**
     * Modèle de référence d'une famille pour le mode page (un seul par famille, pour garder le contexte fixe et
     * cachable) : le plus complet sous REFERENCE_MAX_CHARS caractères, sinon le plus court.
     */
    public function referencePreset(string $componentKey): ?array
    {
        $sizes = [];
        foreach ($this->family($componentKey)['presets'] ?? [] as $i => $preset) {
            $sizes[$i] = strlen(json_encode($preset['composition'] ?? null));
        }
        if (!$sizes) {
            return null;
        }
        $fitting = array_filter($sizes, fn ($size) => $size <= self::REFERENCE_MAX_CHARS);
        $index = $fitting ? array_search(max($fitting), $fitting, true) : array_search(min($sizes), $sizes, true);

        return $this->family($componentKey)['presets'][$index];
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) > 3)));
    }

    private function load(): array
    {
        $path = $this->configStore->path(LandingConfigStore::CATALOGUE);
        if ($this->catalogue === null || $this->loadedFrom !== $path) {
            if (!is_file($path)) {
                throw new \RuntimeException(sprintf('Catalogue de l\'assistant introuvable : %s (voir config/landingpage/README.md)', $path));
            }
            $this->catalogue = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $this->loadedFrom = $path;
        }

        return $this->catalogue;
    }
}
