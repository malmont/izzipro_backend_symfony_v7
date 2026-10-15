<?php

namespace App\Services\LandingAiService;

use App\Services\BoutiqueSettingsService\BoutiqueConfigurationValidator;

/**
 * Charte d'un site composé par l'assistant (mode site, 15/10/2026) : celle que le modèle renvoie avec l'accueil,
 * complétée par ce que les sections composées montrent (couleurs des fonds, des textes et des boutons, polices,
 * arrondi). Les pages système sont ensuite composées avec cette charte et un résumé du style de l'accueil, pour
 * qu'un site entier garde la même allure. Lecture seule.
 */
final class LandingAiCharterExtractor
{
    public const KEYS = ['primaryColor', 'accentColor', 'textColor', 'backgroundColor', 'headingFont', 'bodyFont', 'radius', 'buttonStyle'];
    /** Arrondi à partir duquel un bouton est une pilule */
    private const PILL_RADIUS = 40;

    public function __construct(
        private readonly CompositionInspector $inspector,
        private readonly BoutiqueConfigurationValidator $validator
    ) {
    }

    /**
     * Charte renvoyée par le modèle : clés connues et valides seulement (une valeur refusée est ignorée, pas renvoyée
     * au modèle : la déduction la remplace). Null si rien n'est utilisable.
     *
     * @return array<string, mixed>|null
     */
    public function fromToolInput(mixed $charter): ?array
    {
        if (!is_object($charter)) {
            return null;
        }
        $clean = new \stdClass();
        foreach (self::KEYS as $key) {
            if (isset($charter->$key)) {
                $clean->$key = $charter->$key;
            }
        }
        foreach ($this->validator->validateCharter($clean) as $error) {
            $key = substr($error['path'], strlen('charter.'));
            unset($clean->$key);
        }
        $values = get_object_vars($clean);

        return $values === [] ? null : $values;
    }

    /**
     * Charte complète : les clés données par le modèle, les autres déduites des sections.
     *
     * @param list<object> $compositions
     * @return array<string, mixed>
     */
    public function complete(?array $charter, array $compositions): array
    {
        $result = [];
        $deduced = $this->fromSections($compositions);
        foreach (self::KEYS as $key) {
            $value = $charter[$key] ?? $deduced[$key] ?? null;
            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Charte déduite des sections : fond dominant, couleur de texte dominante, couleur des boutons pleins (principale),
     * couleur des détails (badges, mots mis en avant, icônes ; accent), police des titres et des textes, arrondi et
     * style des boutons.
     *
     * @param list<object> $compositions
     * @return array<string, mixed>
     */
    public function fromSections(array $compositions): array
    {
        $backgrounds = $texts = $buttons = $accents = $headingFonts = $bodyFonts = $radii = [];
        $outline = $solid = 0;
        $count = function (array &$into, mixed $value): void {
            if (is_string($value) && $value !== '' && $value !== 'transparent' && !str_contains($value, 'gradient(')) {
                $into[$value] = ($into[$value] ?? 0) + 1;
            }
        };
        foreach ($compositions as $composition) {
            $count($backgrounds, $composition->background ?? null);
            foreach ($this->inspector->blocks($composition) as $block) {
                $type = $block->type ?? null;
                if ($type === 'title') {
                    $count($texts, $block->color ?? null);
                    $count($headingFonts, $block->fontFamily ?? null);
                    $count($accents, $block->highlightColor ?? null);
                } elseif ($type === 'text') {
                    $count($texts, $block->color ?? null);
                    $count($bodyFonts, $block->fontFamily ?? null);
                } elseif ($type === 'button') {
                    $background = $block->background ?? null;
                    if ($background === 'transparent') {
                        $outline++;
                    } else {
                        $solid++;
                        $count($buttons, $background);
                    }
                    if (is_numeric($block->radius ?? null)) {
                        $radii[(string) $block->radius] = ($radii[(string) $block->radius] ?? 0) + 1;
                    }
                } elseif ($type === 'badge' || $type === 'icon') {
                    $count($accents, $type === 'badge' ? ($block->background ?? null) : ($block->color ?? null));
                }
            }
        }
        $top = function (array $counts, array $except = []): ?string {
            arsort($counts);
            foreach (array_keys($counts) as $value) {
                if (!in_array($value, $except, true)) {
                    return (string) $value;
                }
            }

            return null;
        };
        $charter = [];
        $charter['backgroundColor'] = $top($backgrounds);
        $charter['textColor'] = $top($texts);
        $charter['primaryColor'] = $top($buttons);
        $charter['accentColor'] = $top($accents, array_filter([$charter['primaryColor'], $charter['textColor'], $charter['backgroundColor']]))
            ?? $top($buttons, array_filter([$charter['primaryColor']]));
        $charter['headingFont'] = $top($headingFonts);
        $charter['bodyFont'] = $top($bodyFonts);
        $radius = $top($radii);
        if ($radius !== null) {
            $charter['radius'] = min(100, (float) $radius);
            $charter['buttonStyle'] = (float) $radius >= self::PILL_RADIUS ? 'pill' : ($outline > $solid ? 'outline' : 'solid');
        }

        return array_filter($charter, fn ($value) => $value !== null);
    }

    /**
     * Résumé du style de l'accueil, redonné au modèle pour chaque page système du site : fonds des sections dans
     * l'ordre, polices et poids, boutons (arrondi, hauteur, couleurs), cartes, étiquettes, effets.
     *
     * @param list<array{componentKey: string, composition: object}> $sections
     */
    public function styleSummary(array $sections, ?object $navbar = null, ?object $footer = null): string
    {
        $parts = [];
        $backgrounds = [];
        foreach ($sections as $section) {
            $backgrounds[] = sprintf('%s %s', $section['componentKey'], is_string($section['composition']->background ?? null) ? $section['composition']->background : '(sans fond)');
        }
        if ($backgrounds) {
            $parts[] = 'fonds des sections dans l\'ordre : ' . implode(', ', $backgrounds);
        }
        foreach ([['navbar', $navbar], ['footer', $footer]] as [$name, $composition]) {
            if ($composition !== null && is_string($composition->background ?? null)) {
                $parts[] = sprintf('%s : fond %s', $name, $composition->background);
            }
        }
        $compositions = [...array_column($sections, 'composition'), ...array_filter([$navbar, $footer])];
        $title = $this->typical($compositions, 'title', ['fontFamily', 'fontWeight', 'color']);
        $text = $this->typical($compositions, 'text', ['fontFamily', 'color', 'size', 'lineHeight']);
        $button = $this->typical($compositions, 'button', ['radius', 'minHeight', 'paddingX', 'background', 'color', 'fontFamily']);
        $card = $this->typical($compositions, 'container', ['radius', 'borderWidth', 'borderColor', 'padding'], fn (object $b) => isset($b->background) && $b->background !== 'transparent' && is_string($b->parentId ?? null));
        $label = $this->typical($compositions, 'text', ['fontFamily', 'letterSpacing', 'size', 'color'], fn (object $b) => ($b->textTransform ?? null) === 'uppercase');
        foreach ([['titres', $title], ['textes', $text], ['boutons', $button], ['cartes', $card], ['étiquettes en majuscules', $label]] as [$name, $values]) {
            if ($values !== []) {
                $parts[] = $name . ' : ' . implode(', ', array_map(fn ($k, $v) => "$k $v", array_keys($values), $values));
            }
        }
        $effects = [];
        foreach ($compositions as $composition) {
            foreach ($this->inspector->blocks($composition) as $block) {
                foreach (['animation', 'hover', 'shadow'] as $key) {
                    if (is_string($block->$key ?? null)) {
                        $effects["$key {$block->$key}"] = true;
                    }
                }
            }
            if (!empty($composition->bgGradient)) {
                $effects['bgGradient (halos ou bandes)'] = true;
            }
        }
        $parts[] = 'effets : ' . ($effects ? implode(', ', array_keys($effects)) : 'aucun');

        return implode(' ; ', $parts) . '.';
    }

    /**
     * Valeurs les plus fréquentes des propriétés données sur les blocs d'un type (filtre facultatif).
     *
     * @param list<object> $compositions
     * @param list<string> $keys
     * @return array<string, string>
     */
    private function typical(array $compositions, string $type, array $keys, ?callable $filter = null): array
    {
        $counts = [];
        foreach ($compositions as $composition) {
            foreach ($this->inspector->blocks($composition) as $block) {
                if (($block->type ?? null) !== $type || ($filter !== null && !$filter($block))) {
                    continue;
                }
                foreach ($keys as $key) {
                    $value = $block->$key ?? null;
                    if (is_string($value) || is_int($value) || is_float($value)) {
                        $counts[$key][(string) $value] = ($counts[$key][(string) $value] ?? 0) + 1;
                    }
                }
            }
        }
        $typical = [];
        foreach ($counts as $key => $values) {
            arsort($values);
            $typical[$key] = (string) array_key_first($values);
        }

        return $typical;
    }
}
