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
     * Scènes au défilement (containers layout « scroll ») produites par l'IA : le premier enfant doit être un bloc
     * video qui lit un fichier (url, mediaKey ou liaison), jamais YouTube ni Vimeo, sinon la scène reste vide ; un
     * second bloc video est la version téléphone (9:16), avec les mêmes exigences ; jamais plus de deux (chaque autre
     * enfant direct est une étape) ; une seule scène par section. $skipIds : scènes déjà présentes dans la composition de départ (retouche).
     *
     * @param list<string> $skipIds
     * @return list<array{path: string, message: string}>
     */
    public function sceneErrors(object $composition, array $skipIds = []): array
    {
        $blocks = is_array($composition->blocks ?? null) ? array_values(array_filter($composition->blocks, 'is_object')) : [];
        $errors = [];
        $scenes = 0;
        foreach ($blocks as $i => $block) {
            if (($block->type ?? null) !== 'container' || ($block->layout ?? null) !== 'scroll') {
                continue;
            }
            $scenes++;
            if (in_array($block->id ?? null, $skipIds, true)) {
                continue;
            }
            if ($scenes > 1) {
                $errors[] = ['path' => "blocks[$i].layout", 'message' => 'une seule scène au défilement (layout « scroll ») par page'];
                continue;
            }
            $children = array_values(array_filter($blocks, fn ($b) => ($b->parentId ?? null) === ($block->id ?? null)));
            $video = $children[0] ?? null;
            $url = is_string($video?->url ?? null) ? $video->url : '';
            $message = match (true) {
                ($video?->type ?? null) !== 'video' => 'scène au défilement : le premier enfant du container doit être un bloc video',
                preg_match('#(youtube\.com|youtu\.be|youtube-nocookie\.com|vimeo\.com)#i', $url) === 1 => 'scène au défilement : un fichier vidéo est requis, pas une adresse YouTube ou Vimeo ; sans fichier, pas de scène',
                $url === '' && !is_string($video->mediaKey ?? null) && !is_string($video->bindings->url ?? null) => 'scène au défilement sans fichier vidéo : n\'en propose une que si une vidéo est fournie ou liée',
                default => null,
            };
            $videos = array_values(array_filter($children, fn ($b) => ($b->type ?? null) === 'video'));
            if ($message === null && count($videos) > 2) {
                $message = 'scène au défilement : deux blocs video au plus (la vidéo, puis sa version téléphone) ; les autres enfants sont des étapes';
            }
            // version téléphone : un fichier elle aussi
            $mobile = $videos[1] ?? null;
            $mobileUrl = is_string($mobile?->url ?? null) ? $mobile->url : '';
            if ($message === null && $mobile !== null && (preg_match('#(youtube\.com|youtu\.be|youtube-nocookie\.com|vimeo\.com)#i', $mobileUrl) === 1
                || ($mobileUrl === '' && !is_string($mobile->mediaKey ?? null) && !is_string($mobile->bindings->url ?? null)))) {
                $message = 'scène au défilement : la version téléphone (second bloc video) doit lire un fichier vidéo fourni ou lié, pas YouTube ni Vimeo';
            }
            if ($message !== null) {
                $errors[] = ['path' => "blocks[$i].layout", 'message' => $message];
            }
        }

        return $errors;
    }

    public function sceneCount(object $composition): int
    {
        return count(array_filter(
            is_array($composition->blocks ?? null) ? $composition->blocks : [],
            fn ($b) => is_object($b) && ($b->type ?? null) === 'container' && ($b->layout ?? null) === 'scroll'
        ));
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
