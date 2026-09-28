<?php

namespace App\Services\LandingAiService\Eval;

use App\Services\LandingAiService\CompositionInspector;
use App\Services\LandingAiService\LandingAiCatalogue;
use App\Services\LandingAiService\LandingAiEditResult;
use App\Services\LandingPageSettingsService\ReglableCompositionValidator;

/**
 * Vérifications automatiques communes du jeu d'essai (config/landingpage/ia-assistant-jeu-essai.md) :
 * V1 contrat, V2 types de la famille, V3 médias autorisés, V4 liaisons conservées, V5 chiffres non inventés,
 * V6 blocs non visés identiques. Chaque vérification renvoie ['ok' => bool, 'detail' => string].
 */
final class LandingAiEvalChecks
{
    public function __construct(
        private readonly ReglableCompositionValidator $validator,
        private readonly LandingAiCatalogue $catalogue,
        private readonly CompositionInspector $inspector
    ) {
    }

    /**
     * @param list<string> $allowedMedia
     * @return array<string, array{ok: bool, detail: string}>
     */
    public function common(object $before, LandingAiEditResult $result, string $componentKey, array $allowedMedia, string $prompt): array
    {
        $after = $result->composition;

        $errors = $this->validator->validateComposition($after);
        $v1 = ['ok' => !$errors, 'detail' => $errors ? count($errors) . ' erreur(s), ex. ' . $errors[0]['path'] . ' : ' . $errors[0]['message'] : ''];

        $tools = $this->catalogue->tools($componentKey);
        $badTypes = array_values(array_unique(array_filter(array_map(fn ($b) => $b->type ?? null, $this->inspector->blocks($after)), fn ($t) => !in_array($t, $tools, true))));
        $v2 = ['ok' => !$badTypes, 'detail' => $badTypes ? 'types hors famille : ' . implode(', ', $badTypes) : ''];

        $allowed = array_flip($allowedMedia);
        $badMedia = array_values(array_filter($this->inspector->mediaReferences($after), fn ($r) => !isset($allowed[$r['value']])));
        $v3 = ['ok' => !$badMedia, 'detail' => $badMedia ? count($badMedia) . ' média(s) inventé(s), ex. ' . $badMedia[0]['path'] : ''];

        $lost = [];
        $afterBlocks = $this->inspector->blocksById($after);
        foreach ($this->inspector->blocksById($before) as $id => $block) {
            if (!isset($afterBlocks[$id])) {
                continue;
            }
            foreach ((array) ($block->bindings ?? []) as $key => $path) {
                if ((($afterBlocks[$id]->bindings ?? null)?->$key ?? null) !== $path) {
                    $lost[] = "$id.$key";
                }
            }
        }
        foreach ((array) ($before->bindings ?? []) as $key => $path) {
            if ((($after->bindings ?? null)?->$key ?? null) !== $path) {
                $lost[] = "section.$key";
            }
        }
        $v4 = ['ok' => !$lost, 'detail' => $lost ? 'liaisons perdues : ' . implode(', ', array_slice($lost, 0, 5)) : ''];

        $known = array_flip(array_merge($this->numbers($this->allTexts($before)), $this->numbers($prompt)));
        $invented = array_values(array_unique(array_filter($this->numbers($this->allTexts($after)), fn ($n) => !isset($known[$n]))));
        $v5 = ['ok' => !$invented, 'detail' => $invented ? 'chiffres absents des données : ' . implode(', ', array_slice($invented, 0, 5)) : '(chiffres uniquement ; noms propres non mesurés)'];

        $changed = [];
        $touched = array_flip($result->touchedBlockIds);
        foreach ($this->inspector->blocksById($before) as $id => $block) {
            if (!isset($touched[$id]) && (!isset($afterBlocks[$id]) || $this->encode($afterBlocks[$id]) !== $this->encode($block))) {
                $changed[] = $id;
            }
        }
        $v6 = ['ok' => !$changed, 'detail' => $changed ? 'blocs non visés modifiés : ' . implode(', ', array_slice($changed, 0, 5)) : sprintf('%d bloc(s) visé(s)', count($result->touchedBlockIds))];

        return ['V1' => $v1, 'V2' => $v2, 'V3' => $v3, 'V4' => $v4, 'V5' => $v5, 'V6' => $v6];
    }

    /** Textes visibles de la composition (textes de base et traductions), balises HTML retirées */
    public function allTexts(object $composition): string
    {
        $texts = [];
        foreach ($this->inspector->blocks($composition) as $block) {
            foreach (CompositionInspector::TEXT_KEYS as $key) {
                if (is_string($block->$key ?? null)) {
                    $texts[] = $block->$key;
                }
            }
            foreach ((array) ($block->translations ?? []) as $translation) {
                foreach ((array) $translation as $value) {
                    $texts[] = is_string($value) ? $value : implode(' ', array_filter((array) $value, 'is_string'));
                }
            }
        }

        return strip_tags(implode("\n", $texts));
    }

    /** @return list<string> */
    private function numbers(string $text): array
    {
        preg_match_all('/\d+(?:[.,]\d+)?/u', $text, $m);

        return array_map(fn ($n) => str_replace(',', '.', $n), $m[0]);
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
    }
}
