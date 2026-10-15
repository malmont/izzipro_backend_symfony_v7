<?php

namespace App\Services\LandingAiService\Eval;

use App\Services\LandingAiService\CompositionInspector;

/**
 * Défauts de goût courants des pages produites par une IA (V10 du jeu d'essai, 15/10/2026 ; règles reprises des
 * guides « Taste » et « Impeccable » et adaptées au contrat) :
 * - carte dans une carte : un container à fond opaque et marge interne dont un ancêtre est déjà une carte ;
 * - plus de trois polices dans une section ;
 * - texte gris moyen sur un fond coloré (saturé) : terne, et souvent peu lisible ;
 * - bloc sans largeur dans une pile ou une grille (rendu étroit, texte cassé lettre par lettre) ;
 * - plusieurs boutons pleins de couleurs différentes dans une même section (pas de bouton principal).
 * Chaque défaut est une phrase ; la liste vide signifie « rien à signaler ».
 */
final class LandingAiTasteCheck
{
    public const MAX_FONTS = 3;
    private const FULL_WIDTH_LAYOUTS = ['stack', 'grid', 'slides'];

    public function __construct(private readonly CompositionInspector $inspector)
    {
    }

    /** @return list<string> */
    public function issues(object $composition): array
    {
        $blocks = $this->inspector->blocksById($composition);
        $issues = [];

        foreach ($blocks as $id => $block) {
            if ($this->isCard($block) && ($ancestor = $this->cardAncestor($block, $blocks)) !== null) {
                $issues[] = sprintf('carte %s dans la carte %s (une seule carte autour d\'un contenu)', $id, $ancestor);
            }
            $parent = is_string($block->parentId ?? null) ? ($blocks[$block->parentId] ?? null) : null;
            $layout = $parent === null ? (($composition->rootLayout ?? null) === 'stack' ? 'stack' : 'free') : ($parent->layout ?? 'free');
            if (!property_exists($block, 'w') && ($block->fitContent ?? false) !== true && in_array($layout, self::FULL_WIDTH_LAYOUTS, true)) {
                $issues[] = sprintf('bloc %s sans largeur (w) dans une %s', $id, $layout === 'grid' ? 'grille' : 'pile');
            }
            if (in_array($block->type ?? null, ['title', 'text'], true) && is_string($block->color ?? null)) {
                $background = $this->backgroundOf($block, $blocks, $composition);
                if ($background !== null && $this->isMidGrey($block->color) && $this->isSaturated($background)) {
                    $issues[] = sprintf('%s : texte gris moyen (%s) sur un fond coloré (%s)', $id, $block->color, $background);
                }
            }
        }

        $fonts = array_keys($this->inspector->fonts($composition));
        if (count($fonts) > self::MAX_FONTS) {
            $issues[] = sprintf('%d polices dans la section (%s) : trois au plus', count($fonts), implode(', ', $fonts));
        }

        $solid = [];
        foreach ($blocks as $block) {
            if (($block->type ?? null) === 'button' && is_string($block->background ?? null) && $block->background !== 'transparent' && !str_contains($block->background, 'gradient(')) {
                $solid[strtolower($block->background)] = true;
            }
        }
        if (count($solid) > 2) {
            $issues[] = sprintf('%d couleurs de boutons pleins (%s) : un bouton principal, les autres en contour', count($solid), implode(', ', array_keys($solid)));
        }

        return $issues;
    }

    /** Carte : container à fond opaque (ni absent, ni transparent) avec une marge interne ou une bordure */
    private function isCard(object $block): bool
    {
        $background = $block->background ?? null;

        return ($block->type ?? null) === 'container'
            && is_string($background) && $background !== 'transparent' && !$this->isTransparentRgba($background)
            && ((is_numeric($block->padding ?? null) && $block->padding > 0) || (is_numeric($block->borderWidth ?? null) && $block->borderWidth > 0));
    }

    /** @param array<string, object> $blocks */
    private function cardAncestor(object $block, array $blocks): ?string
    {
        $seen = [];
        $current = $block;
        while (is_string($current->parentId ?? null) && isset($blocks[$current->parentId]) && !isset($seen[$current->parentId])) {
            $seen[$current->parentId] = true;
            $current = $blocks[$current->parentId];
            if ($this->isCard($current)) {
                return $current->id;
            }
        }

        return null;
    }

    /** Fond réel d'un bloc de texte : premier ancêtre à fond opaque, sinon le fond de la section */
    private function backgroundOf(object $block, array $blocks, object $composition): ?string
    {
        $seen = [];
        $current = $block;
        while (is_string($current->parentId ?? null) && isset($blocks[$current->parentId]) && !isset($seen[$current->parentId])) {
            $seen[$current->parentId] = true;
            $current = $blocks[$current->parentId];
            $background = $current->background ?? null;
            if (($current->type ?? null) === 'container' && is_string($background) && $background !== 'transparent' && !$this->isTransparentRgba($background)) {
                return $background;
            }
        }
        $background = $composition->background ?? null;

        return is_string($background) && !str_contains($background, 'gradient(') ? $background : null;
    }

    private function isTransparentRgba(string $color): bool
    {
        return preg_match('/rgba\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*(0|0?\.\d+)\s*\)/', $color) === 1 || str_contains($color, 'gradient(');
    }

    /** Gris : saturation faible, luminance ni claire ni foncée */
    private function isMidGrey(string $color): bool
    {
        $rgb = $this->rgb($color);
        if ($rgb === null) {
            return false;
        }
        $luminance = (0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2]) / 255;

        return $this->saturation($rgb) < 0.12 && $luminance > 0.35 && $luminance < 0.72;
    }

    private function isSaturated(string $color): bool
    {
        $rgb = $this->rgb($color);

        return $rgb !== null && $this->saturation($rgb) >= 0.35 && max($rgb) > 40;
    }

    /** @param array{0: int, 1: int, 2: int} $rgb */
    private function saturation(array $rgb): float
    {
        $max = max($rgb);
        $min = min($rgb);

        return $max === 0 ? 0.0 : ($max - $min) / $max;
    }

    /** @return array{0: int, 1: int, 2: int}|null */
    private function rgb(string $color): ?array
    {
        $color = trim($color);
        if (preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color, $m)) {
            $hex = $m[1];
            if (strlen($hex) <= 4) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }

            return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        }
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/', $color, $m)) {
            return [(int) $m[1], (int) $m[2], (int) $m[3]];
        }

        return null;
    }
}
