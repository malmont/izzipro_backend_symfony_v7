<?php

namespace App\Services\LandingAiService\Eval;

use App\Services\LandingAiService\CompositionInspector;

/**
 * Alignement des boutons et badges en « fitContent » (V8 du jeu d'essai, en création et en page). Dans un container
 * en pile, un bloc en fitContent se place selon SON align, les autres selon celui du container. V8 signale le cas
 * qui est presque toujours une erreur : un bouton ou un badge fitContent décalé alors que les textes voisins suivent
 * l'align de la pile (bouton centré sous un titre et un texte à gauche). C'est un signalement à regarder, pas un
 * refus : un bouton, une icône ou une flèche décalés peuvent être voulus (5 modèles du catalogue le font).
 *
 * Align absent : « left ». Mobile non mesuré : la règle y est autre (une pile avec mobile.align center ou right place
 * tous ses enfants ainsi, fitContent ou non ; le mobile.align d'un bloc ne change que l'alignement de son texte).
 */
final class LandingAiAlignCheck
{
    public const CHECKED_TYPES = ['button', 'badge'];
    private const TEXT_TYPES = ['title', 'text'];
    private const DEFAULT_ALIGN = 'left';

    public function __construct(private readonly CompositionInspector $inspector)
    {
    }

    /** @return list<string> boutons et badges fitContent décalés de leur pile : « id : align (pile parent : align) » */
    public function issues(object $composition): array
    {
        $blocks = $this->inspector->blocksById($composition);
        $issues = [];
        foreach ($blocks as $id => $block) {
            $parent = is_string($block->parentId ?? null) ? ($blocks[$block->parentId] ?? null) : null;
            if (($block->fitContent ?? false) !== true || !in_array($block->type ?? null, self::CHECKED_TYPES, true)
                || $parent === null || ($parent->layout ?? null) !== 'stack') {
                continue;
            }
            $align = $block->align ?? self::DEFAULT_ALIGN;
            $stackAlign = $parent->align ?? self::DEFAULT_ALIGN;
            if ($align === $stackAlign) {
                continue;
            }
            // textes voisins : placés selon la pile ; s'ils s'y alignent aussi, le bouton est le seul décalé
            $texts = array_filter($blocks, fn ($b) => ($b->parentId ?? null) === $parent->id && in_array($b->type ?? null, self::TEXT_TYPES, true));
            if ($texts && !array_filter($texts, fn ($b) => ($b->align ?? self::DEFAULT_ALIGN) !== $stackAlign)) {
                $issues[] = sprintf('%s : %s (pile %s et ses textes : %s)', $id, $align, $parent->id, $stackAlign);
            }
        }

        return $issues;
    }
}
