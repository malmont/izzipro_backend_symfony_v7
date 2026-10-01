<?php

namespace App\Services\LandingAiService\Eval;

use App\Services\LandingAiService\CompositionInspector;

/**
 * Contraste des textes sur leur fond réel (V7 du jeu d'essai, en création et en page). Le moteur de rendu ne dessine
 * le « background » que des containers, boutons et badges (absent : blanc opaque) ; celui d'un titre ou d'un texte
 * n'est jamais dessiné. Fond réel : pour un bouton ou un badge, le sien ; sinon le premier container parent qui
 * dessine un fond opaque, puis la section ; les fonds semi-transparents sont superposés. Fond inconnu (image,
 * vidéo, dégradé, couleur liée à une donnée) : bloc non mesuré.
 */
final class LandingAiContrastCheck
{
    public const TEXT_TYPES = ['title', 'text', 'button', 'badge'];
    /** Seuils WCAG : texte courant, grand texte (24 px et plus) */
    public const MIN_RATIO = 4.5;
    public const MIN_RATIO_LARGE = 3.0;
    public const LARGE_SIZE = 24;
    /** Types dont le fond est dessiné (absent : blanc opaque) */
    private const DRAWN_BACKGROUND_TYPES = ['container', 'button', 'badge'];
    private const MAX_DEPTH = 10;

    public function __construct(private readonly CompositionInspector $inspector)
    {
    }

    /** @return list<string> blocs sous le seuil : « id : rapport (couleur sur fond) » */
    public function issues(object $composition): array
    {
        $blocks = $this->inspector->blocksById($composition);
        $issues = [];
        foreach ($blocks as $id => $block) {
            if (!in_array($block->type ?? null, self::TEXT_TYPES, true) || isset($block->bindings->color)) {
                continue;
            }
            $color = $this->rgba($block->color ?? null);
            $background = $this->background($block, $blocks, $composition);
            if ($color === null || $background === null) {
                continue;
            }
            $ratio = $this->ratio($this->blend($color, $background), $background);
            $large = is_numeric($block->size ?? null) ? $block->size >= self::LARGE_SIZE : ($block->type ?? null) === 'title';
            if ($ratio < ($large ? self::MIN_RATIO_LARGE : self::MIN_RATIO)) {
                $issues[] = sprintf('%s : %.1f:1 (%s sur %s)', $id, $ratio, $block->color, sprintf('#%02x%02x%02x', ...$background));
            }
        }

        return $issues;
    }

    /**
     * Fond réel d'un bloc, ou null s'il est inconnu.
     *
     * @param array<string, object> $blocks
     * @return array{int, int, int}|null
     */
    private function background(object $block, array $blocks, object $composition): ?array
    {
        $layers = [];
        $node = $block;
        for ($depth = 0; $node !== null && $depth < self::MAX_DEPTH; $depth++) {
            $drawn = in_array($node->type ?? null, self::DRAWN_BACKGROUND_TYPES, true);
            if ($drawn && (isset($node->bindings->background) || isset($node->bindings->bgImage) || !empty($node->bgImage))) {
                return null;
            }
            $value = !$drawn ? 'transparent' : (property_exists($node, 'background') ? $node->background : '#ffffff');
            if ($value !== 'transparent' && $value !== '' && $value !== null) {
                $layer = $this->rgba($value);
                if ($layer === null) {
                    return null;
                }
                $layers[] = $layer;
                if ($layer[3] >= 1.0) {
                    return $this->flatten($layers);
                }
            }
            $node = is_string($node->parentId ?? null) ? ($blocks[$node->parentId] ?? null) : null;
        }

        foreach (['bgImage', 'bgVideo', 'bgGradient'] as $key) {
            if (!empty($composition->$key) || isset($composition->bindings->$key)) {
                return null;
            }
        }
        if (isset($composition->bindings->background)) {
            return null;
        }
        $section = $composition->background ?? null;
        $layer = in_array($section, [null, '', 'transparent'], true) ? [255, 255, 255, 1.0] : $this->rgba($section);
        if ($layer === null) {
            return null;
        }
        $layers[] = $layer[3] >= 1.0 ? $layer : [...$this->blend($layer, [255, 255, 255]), 1.0];

        return $this->flatten($layers);
    }

    /**
     * @param list<array{int, int, int, float}> $layers du bloc vers le fond ; la dernière est opaque
     * @return array{int, int, int}
     */
    private function flatten(array $layers): array
    {
        $base = array_slice(array_pop($layers), 0, 3);
        foreach (array_reverse($layers) as $layer) {
            $base = $this->blend($layer, $base);
        }

        return $base;
    }

    /**
     * @param array{int, int, int, float} $color
     * @param array{int, int, int} $background
     * @return array{int, int, int}
     */
    private function blend(array $color, array $background): array
    {
        return array_map(fn (int $i) => (int) round($color[$i] * $color[3] + $background[$i] * (1 - $color[3])), [0, 1, 2]);
    }

    /**
     * @param array{int, int, int} $a
     * @param array{int, int, int} $b
     */
    private function ratio(array $a, array $b): float
    {
        $luminance = function (array $rgb): float {
            $linear = array_map(fn ($c) => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $rgb);

            return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
        };
        [$x, $y] = [$luminance($a), $luminance($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /** @return array{int, int, int, float}|null couleur et opacité, ou null (dégradé, valeur illisible) */
    private function rgba(mixed $color): ?array
    {
        if (!is_string($color)) {
            return null;
        }
        $color = strtolower(trim($color));
        if ($color === 'white' || $color === 'black') {
            return $color === 'white' ? [255, 255, 255, 1.0] : [0, 0, 0, 1.0];
        }
        if (preg_match('/^#([0-9a-f]{3})([0-9a-f])?$/', $color, $m)) {
            return [...array_map(fn ($c) => hexdec($c . $c), str_split($m[1])), isset($m[2]) ? hexdec($m[2] . $m[2]) / 255 : 1.0];
        }
        if (preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})?$/', $color, $m)) {
            return [...array_map('hexdec', str_split($m[1], 2)), isset($m[2]) ? hexdec($m[2]) / 255 : 1.0];
        }
        if (preg_match('/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)(?:[\s,\/]+([\d.]+)(%)?)?\s*\)$/', $color, $m)) {
            $alpha = isset($m[4]) && $m[4] !== '' ? (float) $m[4] / (($m[5] ?? '') === '%' ? 100 : 1) : 1.0;

            return [(int) $m[1], (int) $m[2], (int) $m[3], max(0.0, min(1.0, $alpha))];
        }

        return null;
    }
}
