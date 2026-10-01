<?php

namespace App\Services\LandingAiService\Eval;

use App\Services\LandingAiService\CompositionInspector;

/**
 * Alignement des blocs en « fitContent » (V8 du jeu d'essai, en création et en page). Dans un container en pile, un
 * bloc en fitContent se place selon SON align, les autres selon celui du parent : un bouton fitContent centré dans
 * une pile alignée à gauche se retrouve décalé du reste. Align absent : « left ». Réglages mobile non mesurés.
 */
final class LandingAiAlignCheck
{
    private const DEFAULT_ALIGN = 'left';

    public function __construct(private readonly CompositionInspector $inspector)
    {
    }

    /** @return list<string> blocs fitContent dont l'align diffère de celui de leur pile : « id : align (pile parent : align) » */
    public function issues(object $composition): array
    {
        $blocks = $this->inspector->blocksById($composition);
        $issues = [];
        foreach ($blocks as $id => $block) {
            $parent = is_string($block->parentId ?? null) ? ($blocks[$block->parentId] ?? null) : null;
            if (($block->fitContent ?? false) !== true || $parent === null || ($parent->layout ?? null) !== 'stack') {
                continue;
            }
            $align = $block->align ?? self::DEFAULT_ALIGN;
            $parentAlign = $parent->align ?? self::DEFAULT_ALIGN;
            if ($align !== $parentAlign) {
                $issues[] = sprintf('%s : %s (pile %s : %s)', $id, $align, $parent->id, $parentAlign);
            }
        }

        return $issues;
    }
}
