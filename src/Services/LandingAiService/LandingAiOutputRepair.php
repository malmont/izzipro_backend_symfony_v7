<?php

namespace App\Services\LandingAiService;

use App\Services\LandingPageSettingsService\ReglableCompositionValidator;

/**
 * Répare une faute de frappe fréquente du modèle avant la vérification : une clé inconnue faite d'une propriété
 * connue suivie d'un nombre, dont la valeur est ce même nombre (ex. "dividerWidth100": 100, en double de
 * "dividerWidth": 100). Mesuré le 30/09/2026 : 8 refus sur 9 en création venaient de là, chacun coûtant un essai
 * de plus. Toute autre clé inconnue reste une erreur renvoyée au modèle.
 *
 * Fond des containers : le moteur de rendu donne un fond blanc opaque à un container sans « background ». Le modèle
 * l'omet parfois sur un fond coloré (01/10/2026 : titre blanc sur cadre blanc dans un héros bleu nuit, illisible).
 * Un container produit par l'IA sans « background » reçoit donc « transparent », sans nouvel essai.
 *
 * Clés refusées sans ambiguïté par le contrat (01/10/2026 : un essai de page sur deux relancé pour cela, soit une
 * seconde génération complète) : une propriété à null, que le moteur de rendu traite comme absente, et une propriété
 * de style de texte posée sur un type de bloc qui ne l'utilise pas (fontFamily sur un formulaire). Elles sont
 * retirées avant la vérification. Seulement au premier niveau de la section et des blocs : dans « mobile », null a
 * un sens (mobile.minHeight, mobile.align…).
 */
final class LandingAiOutputRepair
{
    /**
     * Propriétés de style de texte, sans contenu ni effet sur la structure : retirées d'un type qui ne les utilise pas.
     * Le contrat décide des types (« Types : … » dans le schéma) : fontFamily reste donc sur un container, qui la
     * transmet à ses enfants.
     */
    public const STYLE_ONLY_PROPERTIES = ['fontFamily', 'lineHeight', 'letterSpacing', 'textShadow', 'underline', 'underlineColor'];

    public function __construct(private readonly ReglableCompositionValidator $validator)
    {
    }

    /**
     * Retire les clés de premier niveau (section, blocs) que le contrat refuse sans ambiguïté : valeur null, ou
     * propriété de STYLE_ONLY_PROPERTIES sur un type de bloc qui ne l'utilise pas. Le reste est laissé à la vérification.
     *
     * @return list<string> chemins des clés retirées
     */
    public function dropRefusedKeys(object $composition): array
    {
        $removed = [];
        foreach ($this->validator->validateComposition($composition) as $error) {
            if (preg_match('/^blocks\[(\d+)\]\.([A-Za-z]\w*)$/', $error['path'], $m)) {
                [$node, $key] = [$composition->blocks[(int) $m[1]] ?? null, $m[2]];
            } elseif (preg_match('/^[A-Za-z]\w*$/', $error['path'])) {
                [$node, $key] = [$composition, $error['path']];
            } else {
                continue;
            }
            if (!is_object($node) || !property_exists($node, $key)) {
                continue;
            }
            $null = $error['message'] === ReglableCompositionValidator::NULL_REFUSED && $node->$key === null;
            $unusedStyle = $node !== $composition && str_starts_with($error['message'], ReglableCompositionValidator::PROPERTY_NOT_FOR_TYPE)
                && in_array($key, self::STYLE_ONLY_PROPERTIES, true);
            if ($null || $unusedStyle) {
                unset($node->$key);
                $removed[] = $error['path'];
            }
        }

        return $removed;
    }

    /** @return list<string> chemins des clés retirées */
    public function repair(object $composition): array
    {
        $removed = [];
        $this->repairNode($composition, $this->validator->propertyNames('section'), '', $removed);
        $blockProperties = $this->validator->propertyNames('block');
        foreach (is_array($composition->blocks ?? null) ? $composition->blocks : [] as $i => $block) {
            if (is_object($block)) {
                $this->repairNode($block, $blockProperties, "blocks[$i].", $removed);
            }
        }

        return $removed;
    }

    /**
     * Média de repli inventé sur un bloc dont le média est lié à une donnée (image, vidéo ou icône avec bindings.url) :
     * le modèle écrit parfois une URL d'exemple à côté de la liaison (02/10/2026 : trois blocs sur une page, d'où une
     * seconde génération complète). La donnée liée fournit le média : l'URL non autorisée est retirée, sans nouvel
     * essai. Un média inventé sur un bloc non lié reste renvoyé au modèle.
     *
     * @param list<string> $allowedMedia
     * @return list<string> chemins des URL retirées
     */
    public function dropInventedBoundMedia(object $composition, array $allowedMedia): array
    {
        $allowed = array_flip($allowedMedia);
        $removed = [];
        foreach (is_array($composition->blocks ?? null) ? $composition->blocks : [] as $i => $block) {
            if (!is_object($block) || !in_array($block->type ?? null, ['image', 'video', 'icon'], true) || !is_string($block->bindings->url ?? null)) {
                continue;
            }
            $url = $block->url ?? null;
            if (is_string($url) && $url !== '' && !isset($allowed[$url])) {
                unset($block->url);
                $removed[] = "blocks[$i].url";
            }
        }

        return $removed;
    }

    /** Dernier moment acceptable pour une étape de scène au défilement : au-delà, elle n'apparaît qu'à la toute fin */
    public const MAX_STEP_AT = 85;

    /**
     * Scène au défilement : une étape dont stepAt dépasse MAX_STEP_AT n'apparaît que lorsque la scène se décroche
     * (02/10/2026 : stepAt 0, 33, 66, 100). Les stepAt de cette scène sont alors retirés : absents, les étapes sont
     * réparties à intervalles égaux, sans nouvel essai. $skipIds : blocs de la composition de départ (retouche).
     *
     * @param list<string> $skipIds
     * @return list<string> chemins des stepAt retirés
     */
    public function dropLateSteps(object $composition, array $skipIds = []): array
    {
        $blocks = is_array($composition->blocks ?? null) ? array_filter($composition->blocks, 'is_object') : [];
        $removed = [];
        foreach ($blocks as $scene) {
            if (($scene->type ?? null) !== 'container' || ($scene->layout ?? null) !== 'scroll' || in_array($scene->id ?? null, $skipIds, true)) {
                continue;
            }
            $steps = array_filter($blocks, fn ($b) => ($b->parentId ?? null) === ($scene->id ?? null) && is_numeric($b->stepAt ?? null));
            if (!array_filter($steps, fn ($b) => $b->stepAt > self::MAX_STEP_AT)) {
                continue;
            }
            foreach ($steps as $i => $step) {
                unset($step->stepAt);
                $removed[] = "blocks[$i].stepAt";
            }
        }

        return $removed;
    }

    /**
     * Écrit background = « transparent » sur les containers qui n'en ont pas. $skipIds : blocs à laisser tels quels
     * (en retouche, ceux de la composition de départ : seuls les blocs ajoutés par l'IA sont complétés).
     *
     * @param list<string> $skipIds
     * @return list<string> identifiants des containers complétés
     */
    public function transparentContainers(object $composition, array $skipIds = []): array
    {
        $completed = [];
        foreach (is_array($composition->blocks ?? null) ? $composition->blocks : [] as $block) {
            if (!is_object($block) || ($block->type ?? null) !== 'container' || property_exists($block, 'background')) {
                continue;
            }
            $id = is_string($block->id ?? null) ? $block->id : '';
            if (in_array($id, $skipIds, true)) {
                continue;
            }
            $block->background = 'transparent';
            $completed[] = $id;
        }

        return $completed;
    }

    /**
     * @param list<string> $known
     * @param list<string> $removed
     */
    private function repairNode(object $node, array $known, string $prefix, array &$removed): void
    {
        foreach (get_object_vars($node) as $key => $value) {
            if (in_array($key, $known, true) || !preg_match('/^([A-Za-z]+?)(-?\d+(?:\.\d+)?)$/', (string) $key, $m)) {
                continue;
            }
            [, $property, $number] = $m;
            if (!in_array($property, $known, true) || !(is_int($value) || is_float($value)) || (float) $value !== (float) $number) {
                continue;
            }
            unset($node->$key);
            if (!property_exists($node, $property)) {
                $node->$property = $value;
            }
            $removed[] = $prefix . $key;
        }
    }
}
