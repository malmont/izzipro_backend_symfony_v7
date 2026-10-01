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
 */
final class LandingAiOutputRepair
{
    public function __construct(private readonly ReglableCompositionValidator $validator)
    {
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
