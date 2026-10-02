<?php

namespace App\Services\LandingAiService\Eval;

use App\Services\LandingAiService\CompositionInspector;

/**
 * Contraste des textes sur leur fond réel (V7 du jeu d'essai, en création et en page). Le moteur de rendu ne dessine
 * le « background » que des containers, boutons et badges (absent : blanc opaque) ; celui d'un titre ou d'un texte
 * n'est jamais dessiné. Fond réel : pour un bouton ou un badge, le sien ; sinon le premier container parent qui
 * dessine un fond opaque, puis la section ; les fonds semi-transparents sont superposés. Fond inconnu (image,
 * vidéo, dégradé, couleur liée à une donnée) : bloc non mesuré.
 *
 * Scène au défilement (container layout « scroll ») : ses étapes sont posées sur une vidéo, dont l'image est
 * inconnue. Le texte d'une étape se mesure sur sa carte composée sur le pire fond possible (du blanc sous une carte
 * sombre, du noir sous une carte claire). Sont signalés : une carte translucide de moins de 70 % d'opacité, et un
 * texte posé directement sur la vidéo.
 *
 * Section en overlayTop (navbar superposée) : transparente, posée sur la première section de la page, dont le fond
 * n'est connu qu'en mode page ($under). Quand la page a défilé, la barre prend scrollBackground et ses textes
 * scrollColor (sinon la couleur de chaque bloc) : état mesuré si scrollBackground est opaque ou presque, sur le pire
 * des fonds possibles derrière lui (blanc, noir). Sans l'un ni l'autre : non mesurée.
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
    /** Fond de défilement d'une barre superposée : mesuré s'il est opaque ou presque */
    private const MIN_SCROLL_OPACITY = 0.8;
    /** Carte d'une étape de scène au défilement : opacité minimale pour rester lisible sur la vidéo */
    public const MIN_STEP_CARD_OPACITY = 0.70;

    public function __construct(private readonly CompositionInspector $inspector)
    {
    }

    /**
     * @param object|null $under section affichée sous une section en overlayTop (en page : la première section)
     * @return list<string> blocs sous le seuil : « id : rapport (couleur sur fond) »
     */
    public function issues(object $composition, ?object $under = null): array
    {
        if (($composition->overlayTop ?? false) !== true) {
            return array_values($this->measure($composition, $this->sectionBases($composition)));
        }

        // Barre superposée (navbar) : transparente sur la section du dessous, puis scrollBackground quand la page a défilé
        $issues = $under !== null ? $this->measure($composition, $this->sectionBases($under), null, false, ' au-dessus de la première section') : [];
        $scroll = $this->rgba($composition->scrollBackground ?? null);
        if ($scroll !== null && $scroll[3] >= self::MIN_SCROLL_OPACITY) {
            $bases = $scroll[3] >= 1.0 ? [array_slice($scroll, 0, 3)] : [$this->blend($scroll, [255, 255, 255]), $this->blend($scroll, [0, 0, 0])];
            $issues += $this->measure($composition, $bases, $this->rgba($composition->scrollColor ?? null), true, ' une fois la page défilée');
        }

        return array_values($issues);
    }

    /**
     * @param list<array{int, int, int}> $bases fonds possibles de la section (vide : inconnu, blocs posés dessus non mesurés)
     * @param array{int, int, int, float}|null $textColor couleur imposée aux textes (scrollColor), sinon celle du bloc
     * @param bool $sectionOnly ne mesurer que les blocs posés sur le fond de la section
     * @return array<string, string> identifiant => défaut (le pire des fonds possibles)
     */
    private function measure(object $composition, array $bases, ?array $textColor = null, bool $sectionOnly = false, string $state = ''): array
    {
        $blocks = $this->inspector->blocksById($composition);
        $issues = [];
        foreach ($blocks as $id => $block) {
            if (!in_array($block->type ?? null, self::TEXT_TYPES, true) || isset($block->bindings->color)) {
                continue;
            }
            $scene = $this->sceneCard($block, $blocks);
            if ($scene !== null) {
                $issue = $this->sceneIssue($block, $scene);
                if ($issue !== null) {
                    $issues[$id] = "$id : $issue";
                }
                continue;
            }
            foreach ($bases ?: [null] as $base) {
                $found = $this->background($block, $blocks, $base);
                $color = $found !== null && $found[1] && $textColor !== null ? $textColor : $this->rgba($block->color ?? null);
                if ($found === null || $color === null || ($sectionOnly && !$found[1])) {
                    continue;
                }
                $ratio = $this->ratio($this->blend($color, $found[0]), $found[0]);
                $large = is_numeric($block->size ?? null) ? $block->size >= self::LARGE_SIZE : ($block->type ?? null) === 'title';
                if ($ratio < ($large ? self::MIN_RATIO_LARGE : self::MIN_RATIO)) {
                    $issues[$id] = sprintf('%s : %.1f:1 (%s sur %s%s)', $id, $ratio, $textColor !== null && $found[1] ? $composition->scrollColor : $block->color, sprintf('#%02x%02x%02x', ...$found[0]), $state);
                    break;
                }
            }
        }

        return $issues;
    }

    /**
     * Bloc d'une étape de scène au défilement posé sur la vidéo : calques translucides de sa carte, du bloc vers la
     * vidéo (vide : posé directement sur la vidéo). Null : hors d'une scène, ou sur un fond opaque (mesure ordinaire),
     * ou fond inconnu.
     *
     * @param array<string, object> $blocks
     * @return list<array{int, int, int, float}>|null
     */
    private function sceneCard(object $block, array $blocks): ?array
    {
        $layers = [];
        $node = $block;
        for ($depth = 0; $node !== null && $depth < self::MAX_DEPTH; $depth++) {
            if (($node->type ?? null) === 'container' && ($node->layout ?? null) === 'scroll') {
                return $layers;
            }
            if (in_array($node->type ?? null, self::DRAWN_BACKGROUND_TYPES, true)) {
                if (isset($node->bindings->background) || isset($node->bindings->bgImage) || !empty($node->bgImage)) {
                    return null;
                }
                $value = property_exists($node, 'background') ? $node->background : '#ffffff';
                if ($value !== 'transparent' && $value !== '' && $value !== null) {
                    $layer = $this->rgba($value);
                    if ($layer === null || $layer[3] >= 1.0) {
                        return null;
                    }
                    $layers[] = $layer;
                }
            }
            $node = is_string($node->parentId ?? null) ? ($blocks[$node->parentId] ?? null) : null;
        }

        return null;
    }

    /** @param list<array{int, int, int, float}> $layers calques translucides entre le texte et la vidéo */
    private function sceneIssue(object $block, array $layers): ?string
    {
        if ($layers === []) {
            return 'texte posé directement sur la vidéo de la scène (non mesurable : le mettre dans une carte)';
        }
        $opacity = 1 - array_product(array_map(fn ($layer) => 1 - $layer[3], $layers));
        if ($opacity < self::MIN_STEP_CARD_OPACITY - 1e-9) {
            return sprintf('carte d\'étape opaque à %d %% seulement sur la vidéo (%d %% au moins)', round($opacity * 100), self::MIN_STEP_CARD_OPACITY * 100);
        }
        $color = $this->rgba($block->color ?? null);
        if ($color === null) {
            return null;
        }
        $large = is_numeric($block->size ?? null) ? $block->size >= self::LARGE_SIZE : ($block->type ?? null) === 'title';
        $worst = null;
        foreach ([[255, 255, 255], [0, 0, 0]] as $behind) {
            $background = $this->flatten([...$layers, [...$behind, 1.0]]);
            $ratio = $this->ratio($this->blend($color, $background), $background);
            $worst = $worst === null || $ratio < $worst[0] ? [$ratio, $background] : $worst;
        }

        return $worst[0] < ($large ? self::MIN_RATIO_LARGE : self::MIN_RATIO)
            ? sprintf('%.1f:1 (%s sur %s, pire image de la vidéo derrière la carte)', $worst[0], $block->color, sprintf('#%02x%02x%02x', ...$worst[1]))
            : null;
    }

    /**
     * Fond réel d'un bloc et s'il est celui de la section, ou null s'il est inconnu.
     *
     * @param array<string, object> $blocks
     * @param array{int, int, int}|null $base fond de la section (null : inconnu)
     * @return array{0: array{int, int, int}, 1: bool}|null
     */
    private function background(object $block, array $blocks, ?array $base): ?array
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
                    return [$this->flatten($layers), false];
                }
            }
            $node = is_string($node->parentId ?? null) ? ($blocks[$node->parentId] ?? null) : null;
        }
        if ($base === null) {
            return null;
        }
        $layers[] = [...$base, 1.0];

        return [$this->flatten($layers), count($layers) === 1];
    }

    /**
     * Fond d'une section : sa couleur (transparente ou absente : blanc ; semi-transparente : posée sur du blanc).
     * Image, vidéo, dégradé ou couleur liée à une donnée : inconnu.
     *
     * @return list<array{int, int, int}> un fond, ou aucun s'il est inconnu
     */
    private function sectionBases(object $composition): array
    {
        foreach (['bgImage', 'bgVideo', 'bgGradient'] as $key) {
            if (!empty($composition->$key) || isset($composition->bindings->$key)) {
                return [];
            }
        }
        if (isset($composition->bindings->background)) {
            return [];
        }
        $section = $composition->background ?? null;
        $layer = in_array($section, [null, '', 'transparent'], true) ? [255, 255, 255, 1.0] : $this->rgba($section);

        return $layer === null ? [] : [$this->blend($layer, [255, 255, 255])];
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
