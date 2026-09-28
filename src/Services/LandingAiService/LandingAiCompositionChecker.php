<?php

namespace App\Services\LandingAiService;

use App\Services\LandingPageSettingsService\ReglableCompositionValidator;

/**
 * Vérifications d'une composition produite par l'IA, dans l'ordre : contrat (schéma + règles entre blocs),
 * types de blocs de la famille, médias autorisés. Chemins au format du frontend (blocks[3].url…).
 */
final class LandingAiCompositionChecker
{
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
}
