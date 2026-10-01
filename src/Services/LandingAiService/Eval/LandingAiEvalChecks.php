<?php

namespace App\Services\LandingAiService\Eval;

use App\Services\LandingAiService\CompositionInspector;
use App\Services\LandingAiService\LandingAiCatalogue;
use App\Services\LandingAiService\LandingAiEditResult;
use App\Services\LandingPageSettingsService\ReglableCompositionValidator;

/**
 * Vérifications automatiques communes du jeu d'essai (config/landingpage/ia-assistant-jeu-essai.md) :
 * V1 contrat, V2 types de la famille, V3 médias autorisés, V4 liaisons conservées, V5 chiffres non inventés,
 * V6 blocs non visés identiques, V7 contraste des textes sur leur fond réel (création et page). Chaque vérification renvoie ['ok' => bool, 'detail' => string].
 */
final class LandingAiEvalChecks
{
    public function __construct(
        private readonly ReglableCompositionValidator $validator,
        private readonly LandingAiCatalogue $catalogue,
        private readonly CompositionInspector $inspector,
        private readonly LandingAiContrastCheck $contrast
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

        return ['V1' => $v1, 'V2' => $v2, 'V3' => $v3, 'V4' => $v4, 'V5' => $v5, 'V6' => $v6, 'V7' => ['ok' => null, 'detail' => 'sans objet en retouche (contraste de départ non maîtrisé)']];
    }

    /**
     * Vérifications d'une création : V1 à V3 et V5 comme en retouche, V7 (contraste, voir LandingAiContrastCheck) ; V4 (liaisons conservées) et V6 (blocs non
     * visés identiques) ne s'appliquent pas à une composition neuve. V5 compare aux textes de la demande et des
     * données du site, jamais à ceux des modèles (exemples de mise en page).
     *
     * @param list<string> $allowedMedia
     * @return array<string, array{ok: bool|null, detail: string}>
     */
    public function commonCreate(object $composition, string $componentKey, array $allowedMedia, string $knownText): array
    {
        $errors = $this->validator->validateComposition($composition);
        $tools = $this->catalogue->tools($componentKey);
        $badTypes = array_values(array_unique(array_filter(array_map(fn ($b) => $b->type ?? null, $this->inspector->blocks($composition)), fn ($t) => !in_array($t, $tools, true))));
        $allowed = array_flip($allowedMedia);
        $badMedia = array_values(array_filter($this->inspector->mediaReferences($composition), fn ($r) => !isset($allowed[$r['value']])));
        $known = array_flip($this->numbers($knownText));
        $invented = array_values(array_unique(array_filter($this->numbers($this->allTexts($composition)), fn ($n) => !isset($known[$n]))));
        $weak = $this->contrast->issues($composition);

        return [
            'V1' => ['ok' => !$errors, 'detail' => $errors ? count($errors) . ' erreur(s), ex. ' . $errors[0]['path'] . ' : ' . $errors[0]['message'] : ''],
            'V2' => ['ok' => !$badTypes, 'detail' => $badTypes ? 'types hors famille : ' . implode(', ', $badTypes) : ''],
            'V3' => ['ok' => !$badMedia, 'detail' => $badMedia ? count($badMedia) . ' média(s) inventé(s), ex. ' . $badMedia[0]['path'] : ''],
            'V4' => ['ok' => null, 'detail' => 'sans objet en création'],
            'V5' => ['ok' => !$invented, 'detail' => $invented ? 'chiffres absents des données : ' . implode(', ', array_slice($invented, 0, 5)) : '(chiffres uniquement ; noms propres non mesurés)'],
            'V6' => ['ok' => null, 'detail' => 'sans objet en création'],
            'V7' => ['ok' => !$weak, 'detail' => $weak ? 'contraste insuffisant : ' . implode(' ; ', array_slice($weak, 0, 4)) : ''],
        ];
    }

    /**
     * Vérifications d'une page : celles de la création sur chaque section ; une vérification échoue dès qu'une
     * section échoue (détail : première section en défaut).
     *
     * @param list<array{componentKey: string, dataType: ?string, composition: object}> $sections
     * @return array<string, array{ok: bool|null, detail: string}>
     */
    public function commonPage(array $sections, array $allowedMedia, string $knownText): array
    {
        $checks = [];
        foreach ($sections as $i => $section) {
            foreach ($this->commonCreate($section['composition'], $section['componentKey'], $allowedMedia, $knownText) as $v => $check) {
                if (!isset($checks[$v]) || ($checks[$v]['ok'] === true && $check['ok'] === false)) {
                    $checks[$v] = $check['ok'] === false ? ['ok' => false, 'detail' => sprintf('section %d (%s) : %s', $i + 1, $section['componentKey'], $check['detail'])] : $check;
                }
            }
        }

        return $checks;
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
