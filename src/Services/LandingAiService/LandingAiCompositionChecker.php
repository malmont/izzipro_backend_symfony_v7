<?php

namespace App\Services\LandingAiService;

use App\Services\LandingPageSettingsService\ReglableCompositionValidator;

/**
 * Vérifications d'une composition produite par l'IA, dans l'ordre : contrat (schéma + règles entre blocs),
 * types de blocs de la famille, médias autorisés. Chemins au format du frontend (blocks[3].url…).
 */
final class LandingAiCompositionChecker
{
    /** Types, hors container, dont le moteur de rendu dessine le fond (absent : blanc opaque) */
    public const DRAWN_BACKGROUND_TYPES = ['button', 'badge'];

    public function __construct(
        private readonly ReglableCompositionValidator $validator,
        private readonly LandingAiCatalogue $catalogue,
        private readonly CompositionInspector $inspector
    ) {
    }

    /**
     * @param list<string> $allowedMedia
     * @return list<array{path: string, message: string}>
     */
    public function check(object $composition, string $componentKey, array $allowedMedia): array
    {
        $errors = $this->validator->validateComposition($composition);

        $tools = $this->catalogue->tools($componentKey);
        foreach (is_array($composition->blocks ?? null) ? $composition->blocks : [] as $i => $block) {
            $type = is_object($block) ? ($block->type ?? null) : null;
            if (is_string($type) && $tools && !in_array($type, $tools, true)) {
                $errors[] = ['path' => "blocks[$i].type", 'message' => sprintf('type « %s » non disponible pour cette famille (autorisés : %s)', $type, implode(', ', $tools))];
            }
        }

        $allowed = array_flip($allowedMedia);
        foreach ($this->inspector->mediaReferences($composition) as $ref) {
            if (!isset($allowed[$ref['value']])) {
                $errors[] = ['path' => $ref['path'], 'message' => 'média absent de la liste autorisée : n\'invente pas d\'URL ni de clé, laisse l\'emplacement vide et signale-le dans warnings'];
            }
        }

        return $errors;
    }

    /**
     * Boutons et badges produits par l'IA sans « background » : le moteur de rendu les dessine sur fond blanc opaque,
     * ce qui est rarement voulu, et la couleur ne se devine pas : erreur renvoyée au modèle. $skipIds : blocs à ne pas
     * vérifier (en retouche, ceux de la composition de départ). Les containers sont complétés sans nouvel essai
     * (LandingAiOutputRepair::transparentContainers) ; les autres types ne dessinent pas de fond.
     *
     * @param list<string> $skipIds
     * @return list<array{path: string, message: string}>
     */
    public function missingBackgrounds(object $composition, array $skipIds = []): array
    {
        $errors = [];
        foreach (is_array($composition->blocks ?? null) ? $composition->blocks : [] as $i => $block) {
            if (!is_object($block) || !in_array($block->type ?? null, self::DRAWN_BACKGROUND_TYPES, true) || property_exists($block, 'background')) {
                continue;
            }
            if (!in_array($block->id ?? null, $skipIds, true)) {
                $errors[] = ['path' => "blocks[$i].background", 'message' => sprintf('background attendu sur un bloc %s (absent : fond blanc opaque) : écris sa couleur, ou « transparent »', $block->type)];
            }
        }

        return $errors;
    }
}
