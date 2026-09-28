<?php

namespace App\Services\LandingAiService;

/**
 * Lecture des compositions : médias référencés, couleurs, polices, textes. Aucune modification.
 */
final class CompositionInspector
{
    public const MEDIA_KEY_PATTERN = '/^[a-fA-F0-9]{64}$/';

    /** Propriétés contenant une couleur ou un dégradé */
    private const COLOR_KEYS = ['background', 'color', 'borderColor', 'accent', 'accentBackground', 'bulletColor', 'bulletBackground', 'headingColor',
        'highlightColor', 'leadColor', 'listDivider', 'listSeparator', 'underlineColor', 'activeColor', 'hoverColor', 'activeBackground', 'navUnderline',
        'drawerBackground', 'drawerColor', 'buttonBg', 'buttonColor', 'bgOverlay', 'scrollBackground', 'scrollColor', 'altBackground', 'featuredBackground',
        'featuredColor', 'pickedColor', 'textGradient', 'bgGradient'];

    private const FONT_KEYS = ['fontFamily', 'headingFont', 'highlightFont'];

    /** Textes visibles d'un bloc (hors liaisons) */
    public const TEXT_KEYS = ['text', 'label', 'alt', 'placeholder', 'highlightWord', 'tabLabel', 'offer'];

    /**
     * Médias référencés : images, vidéos, fonds, clés de média. Les liens (boutons, liens de bloc) n'en font pas partie.
     *
     * @return list<array{path: string, value: string}>
     */
    public function mediaReferences(object $composition): array
    {
        $refs = [];
        $add = function (string $path, mixed $value) use (&$refs) {
            if (is_string($value) && $value !== '') {
                $refs[] = ['path' => $path, 'value' => $value];
            }
        };
        foreach (['bgImage', 'bgVideo'] as $key) {
            $add($key, $composition->$key ?? null);
        }
        foreach ($this->blocks($composition) as $i => $block) {
            $type = $block->type ?? null;
            if (in_array($type, ['image', 'video', 'icon'], true)) {
                $add("blocks[$i].url", $block->url ?? null);
            }
            foreach (['mediaKey', 'poster', 'bgImage'] as $key) {
                $add("blocks[$i].$key", $block->$key ?? null);
            }
            foreach (is_array($block->images ?? null) ? $block->images : [] as $j => $image) {
                if (is_object($image)) {
                    $add("blocks[$i].images[$j].url", $image->url ?? null);
                    $add("blocks[$i].images[$j].key", $image->key ?? null);
                }
            }
            foreach (is_array($block->mediaCycle ?? null) ? $block->mediaCycle : [] as $j => $media) {
                $add("blocks[$i].mediaCycle[$j]", $media);
            }
        }

        return $refs;
    }

    /** @return array<string, int> couleur => nombre d'utilisations */
    public function colors(object $composition): array
    {
        return $this->count($composition, self::COLOR_KEYS);
    }

    /** @return array<string, int> police => nombre d'utilisations */
    public function fonts(object $composition): array
    {
        return $this->count($composition, self::FONT_KEYS);
    }

    /** @return array<string, object> identifiant => bloc */
    public function blocksById(object $composition): array
    {
        $byId = [];
        foreach ($this->blocks($composition) as $block) {
            if (is_string($block->id ?? null)) {
                $byId[$block->id] = $block;
            }
        }

        return $byId;
    }

    /** @return list<object> */
    public function blocks(object $composition): array
    {
        return array_values(array_filter(is_array($composition->blocks ?? null) ? $composition->blocks : [], 'is_object'));
    }

    private function count(object $composition, array $keys): array
    {
        $counts = [];
        $visit = function (object $node) use (&$counts, $keys) {
            foreach ($keys as $key) {
                $value = $node->$key ?? null;
                if (is_string($value) && $value !== '' && $value !== 'transparent') {
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
            }
            if (is_object($node->repeat ?? null)) {
                foreach (['altBackground', 'featuredBackground', 'featuredColor', 'pickedColor'] as $key) {
                    if (in_array($key, $keys, true) && is_string($node->repeat->$key ?? null)) {
                        $counts[$node->repeat->$key] = ($counts[$node->repeat->$key] ?? 0) + 1;
                    }
                }
            }
        };
        $visit($composition);
        foreach ($this->blocks($composition) as $block) {
            $visit($block);
        }

        return $counts;
    }
}
