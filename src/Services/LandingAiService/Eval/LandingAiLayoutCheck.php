<?php

namespace App\Services\LandingAiService\Eval;

use App\Services\LandingAiService\CompositionInspector;

/**
 * Défauts de mise en page relevés sur une page réelle le 02/10/2026 (V9 du jeu d'essai, en création et en page) :
 * - formulaire de contact dans un double cadre : le bloc form de contact dessine déjà sa carte (fond, bordure, arrondi,
 *   ombre) ; son parent direct ne doit pas être une seconde carte (fond opaque avec marge interne, bordure ou ombre) ;
 * - liste de services sans prix : sur un site, le prix est dans item.subtitle (« À partir de 1 200 $ ») ou dans
 *   item.price ; une liste de services lie les deux.
 * Les images liées affichées en « cover » ne sont pas mesurées : 11 modèles du catalogue sur 19 le font à dessein.
 */
final class LandingAiLayoutCheck
{
    public const SERVICE_PRICE_BINDINGS = ['item.subtitle', 'item.price'];

    public function __construct(private readonly CompositionInspector $inspector)
    {
    }

    /** @return list<string> */
    public function issues(object $composition): array
    {
        $blocks = $this->inspector->blocksById($composition);
        $issues = [];
        foreach ($blocks as $id => $block) {
            if (($block->type ?? null) === 'form' && ($block->formType ?? null) === 'contact') {
                $parent = is_string($block->parentId ?? null) ? ($blocks[$block->parentId] ?? null) : null;
                if ($parent !== null && $this->isCard($parent)) {
                    $issues[] = sprintf('formulaire %s dans un double cadre (parent %s)', $id, $parent->id);
                }
            }
            if (($block->type ?? null) === 'container' && (($block->repeat ?? null)?->source ?? null) === 'services') {
                $bound = [];
                foreach ($blocks as $child) {
                    if ($this->isInside($child, $id, $blocks)) {
                        array_push($bound, ...array_values(array_filter((array) ($child->bindings ?? []), 'is_string')));
                    }
                }
                if ($missing = array_diff(self::SERVICE_PRICE_BINDINGS, $bound)) {
                    $issues[] = sprintf('liste de services %s sans liaison %s', $id, implode(' ni ', $missing));
                }
            }
        }

        return $issues;
    }

    /** Container qui dessine une carte : fond opaque (absent : blanc opaque) avec marge interne, ou bordure, ou ombre */
    private function isCard(object $container): bool
    {
        $background = property_exists($container, 'background') ? $container->background : '#ffffff';
        $opaque = !in_array($background, ['transparent', '', null], true);

        return ($opaque && (($container->padding ?? 0) > 0 || ($container->paddingX ?? 0) > 0))
            || ($container->borderWidth ?? 0) > 0
            || !empty($container->shadow);
    }

    /** @param array<string, object> $blocks */
    private function isInside(object $block, string $ancestorId, array $blocks): bool
    {
        for ($depth = 0, $parent = $block->parentId ?? null; is_string($parent) && $depth < 10; $depth++) {
            if ($parent === $ancestorId) {
                return true;
            }
            $parent = ($blocks[$parent] ?? null)?->parentId ?? null;
        }

        return false;
    }
}
