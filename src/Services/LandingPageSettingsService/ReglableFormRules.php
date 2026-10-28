<?php

namespace App\Services\LandingPageSettingsService;

/**
 * Formulaires à étapes des compositions réglables (contrat du lot 1, figé par le frontend le 27/10/2026) : règles
 * entre blocs que le JSON Schema ne peut pas exprimer, identiques à celles de l'éditeur (formSteps.js, formRuleErrors).
 * Un formulaire = un container layout « steps » ; chaque container enfant direct est une étape ; l'étape
 * stepRole « success » s'affiche après l'envoi. Sans container « steps », aucune règle ne s'applique.
 * Classe sans dépendance : appelée par ReglableCompositionValidator (landing page, boutique, modèles de site,
 * restauration du journal, synchronisation et sorties de l'assistant IA).
 */
final class ReglableFormRules
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';
    public const MAX_QUESTIONS = 30;
    /** Actions de bouton propres aux formulaires à étapes */
    public const FORM_ACTIONS = ['next', 'prev', 'submit', 'restart'];
    public const CHOICE_TYPES = ['select', 'choice'];
    /** fieldRole => fieldType exigés */
    public const ROLE_TYPES = [
        'email' => ['email'], 'phone' => ['tel'], 'date' => ['date'], 'guests' => ['number'],
        'service' => ['select', 'choice'], 'slot' => ['select', 'choice'],
    ];
    /** formTarget qui exigent une question courriel obligatoire (la cible crée une réservation ou un contact) */
    public const TARGETS_NEEDING_EMAIL = ['reservation', 'contact'];
    public const MAX_UID_LENGTH = 100;
    private const MAX_ANCESTORS = 20;

    /**
     * Règles d'une composition.
     *
     * @param list<mixed> $blocks
     * @param string $blocksPath chemin du tableau des blocs (ex. tabs[0].sections[1].reglableConfig.blocks)
     * @return list<array{path: string, message: string}>
     */
    public static function compositionErrors(array $blocks, string $blocksPath): array
    {
        $byId = [];
        foreach ($blocks as $i => $block) {
            if (is_object($block) && is_string($block->id ?? null) && !isset($byId[$block->id])) {
                $byId[$block->id] = $i;
            }
        }
        $at = fn (int $i, string $property) => "{$blocksPath}[$i].$property";
        $errors = [];

        // 1. Un seul container « steps » par section, même imbriqué
        $steps = array_keys(array_filter($blocks, [self::class, 'isSteps']));
        foreach (array_slice($steps, 1) as $i) {
            $errors[] = ['path' => $at($i, 'layout'), 'message' => 'un seul formulaire à étapes (container layout « steps ») par section'];
        }
        if ($steps === [] && !self::usesFormBlocks($blocks)) {
            return $errors;
        }

        // Pour chaque bloc : formulaire (container steps ancêtre le plus proche) et étape (enfant direct de ce container)
        $context = [];
        foreach ($blocks as $i => $block) {
            if (!is_object($block)) {
                continue;
            }
            $ancestors = self::ancestors($block, $blocks, $byId);
            $form = $step = null;
            foreach ($ancestors as $k => $index) {
                if (self::isSteps($blocks[$index])) {
                    $form = $index;
                    $step = $k >= 1 ? $ancestors[$k - 1] : null;
                    break;
                }
            }
            $context[$i] = ['form' => $form, 'step' => $step, 'success' => $step !== null && ($blocks[$step]->stepRole ?? null) === 'success'];
        }

        // Questions de chaque formulaire (pour summaryFields et formTarget)
        $namesByForm = [];
        foreach ($blocks as $i => $block) {
            if (is_object($block) && ($block->type ?? null) === 'field' && $context[$i]['step'] !== null && is_string($block->name ?? null)) {
                $namesByForm[$context[$i]['form']][] = $block->name;
            }
        }

        $seenNames = $seenRoles = $questions = $submits = $successes = [];
        foreach ($blocks as $i => $block) {
            if (!is_object($block)) {
                continue;
            }
            $type = $block->type ?? null;
            ['form' => $form, 'step' => $step] = $context[$i];

            // 2. field et summary dans une étape d'un formulaire
            if (in_array($type, ['field', 'summary'], true) && $step === null) {
                $errors[] = ['path' => $at($i, 'type'), 'message' => sprintf('%s se place dans une étape d\'un formulaire (container enfant direct d\'un container layout « steps »)', $type === 'field' ? 'une question (field)' : 'un résumé (summary)')];
            }
            // 3 et 5. Boutons du formulaire
            $action = $block->action ?? null;
            if ($type === 'button' && in_array($action, self::FORM_ACTIONS, true)) {
                if ($form === null) {
                    $errors[] = ['path' => $at($i, 'action'), 'message' => sprintf('action « %s » seulement dans un formulaire à étapes (container layout « steps »)', $action)];
                } elseif ($action === 'submit' && ($submits[$form] = ($submits[$form] ?? 0) + 1) > 1) {
                    $errors[] = ['path' => $at($i, 'action'), 'message' => 'un seul bouton submit par formulaire'];
                }
            }
            // 4. stepRole : étape (container enfant direct d'un container steps), une seule étape success
            if (property_exists($block, 'stepRole') && $block->stepRole !== null) {
                $parent = is_string($block->parentId ?? null) && isset($byId[$block->parentId]) ? $byId[$block->parentId] : null;
                if ($type !== 'container' || $parent === null || !self::isSteps($blocks[$parent])) {
                    $errors[] = ['path' => $at($i, 'stepRole'), 'message' => 'stepRole seulement sur une étape (container enfant direct d\'un container layout « steps »)'];
                } elseif ($block->stepRole === 'success' && ($successes[$parent] = ($successes[$parent] ?? 0) + 1) > 1) {
                    $errors[] = ['path' => $at($i, 'stepRole'), 'message' => 'une seule étape success par formulaire'];
                }
            }
            if ($type === 'field') {
                array_push($errors, ...self::fieldErrors($block, $i, $form ?? -1, $context[$i]['success'], $at, $seenNames, $seenRoles, $questions));
            }
            // 9. summaryFields : questions du même formulaire
            if ($type === 'summary' && is_array($block->summaryFields ?? null)) {
                $known = $namesByForm[$form] ?? [];
                foreach ($block->summaryFields as $name) {
                    if (!is_string($name) || !in_array($name, $known, true)) {
                        $errors[] = ['path' => $at($i, 'summaryFields'), 'message' => sprintf('« %s » n\'est pas une question de ce formulaire', is_scalar($name) ? (string) $name : '?')];
                    }
                }
            }
        }

        // 10. formTarget reservation ou contact : une question courriel obligatoire
        foreach ($steps as $i) {
            $target = $blocks[$i]->formTarget ?? null;
            if (!in_array($target, self::TARGETS_NEEDING_EMAIL, true)) {
                continue;
            }
            $hasEmail = false;
            foreach ($blocks as $j => $block) {
                $hasEmail = $hasEmail || (is_object($block) && ($block->type ?? null) === 'field' && ($context[$j]['form'] ?? null) === $i
                    && ($block->fieldType ?? null) === 'email' && ($block->fieldRole ?? null) === 'email' && ($block->required ?? false) === true);
            }
            if (!$hasEmail) {
                $errors[] = ['path' => $at($i, 'formTarget'), 'message' => sprintf('formTarget « %s » : une question fieldType « email », fieldRole « email », required true est obligatoire', $target)];
            }
        }

        return $errors;
    }

    /**
     * Règles du document : une section qui contient un formulaire porte un uid, unique dans le document ; aucun
     * formulaire dans la navbar ni le footer.
     *
     * @param array<string, mixed> $compositions chemin => composition (ReglableCompositionValidator::compositions)
     * @return list<array{path: string, message: string}>
     */
    public static function documentErrors(mixed $configuration, array $compositions): array
    {
        $errors = [];
        if (!is_object($configuration)) {
            return $errors;
        }
        foreach (['navbar', 'footer'] as $key) {
            $composition = $compositions["$key.reglableConfig"] ?? null;
            if (is_object($composition) && self::hasForm($composition)) {
                $errors[] = ['path' => "$key.reglableConfig.blocks", 'message' => sprintf('pas de formulaire à étapes dans %s', $key === 'navbar' ? 'la navbar' : 'le footer')];
            }
        }

        $uids = [];
        foreach (is_array($configuration->tabs ?? null) ? $configuration->tabs : [] as $t => $tab) {
            foreach (is_object($tab) && is_array($tab->sections ?? null) ? $tab->sections : [] as $s => $section) {
                if (is_object($section) && is_string($section->uid ?? null) && $section->uid !== '') {
                    $uids[$section->uid][] = "tabs[$t].sections[$s]";
                }
            }
        }
        foreach (is_array($configuration->tabs ?? null) ? $configuration->tabs : [] as $t => $tab) {
            foreach (is_object($tab) && is_array($tab->sections ?? null) ? $tab->sections : [] as $s => $section) {
                $composition = $compositions["tabs[$t].sections[$s].reglableConfig"] ?? null;
                if (!is_object($composition) || !self::hasForm($composition)) {
                    continue;
                }
                $path = "tabs[$t].sections[$s].uid";
                $uid = $section->uid ?? null;
                if (!is_string($uid) || trim($uid) === '' || mb_strlen($uid) > self::MAX_UID_LENGTH || preg_match('/[<>\s]/', $uid)) {
                    $errors[] = ['path' => $path, 'message' => sprintf('une section qui contient un formulaire porte un uid (identifiant stable posé par l\'éditeur, %d caractères au plus, sans espace ni < >)', self::MAX_UID_LENGTH)];
                } elseif (count($uids[$uid]) > 1) {
                    $others = array_values(array_diff($uids[$uid], ["tabs[$t].sections[$s]"]));
                    $errors[] = ['path' => $path, 'message' => sprintf('uid « %s » déjà porté par %s : il doit être unique dans le document', $uid, implode(', ', $others))];
                }
            }
        }

        return $errors;
    }

    /** La composition contient-elle un formulaire à étapes ? */
    public static function hasForm(object $composition): bool
    {
        foreach (is_array($composition->blocks ?? null) ? $composition->blocks : [] as $block) {
            if (self::isSteps($block)) {
                return true;
            }
        }

        return false;
    }

    /** Nombre de blocs de formulaire à étapes (containers steps, questions, résumés) : garde-fou de l'assistant IA */
    public static function formBlockCount(object $composition): int
    {
        $count = 0;
        foreach (is_array($composition->blocks ?? null) ? $composition->blocks : [] as $block) {
            $count += (self::isSteps($block) || (is_object($block) && in_array($block->type ?? null, ['field', 'summary'], true))) ? 1 : 0;
        }

        return $count;
    }

    public static function isSteps(mixed $block): bool
    {
        return is_object($block) && ($block->type ?? null) === 'container' && ($block->layout ?? null) === 'steps';
    }

    /** 6, 7, 8, 11 : une question */
    private static function fieldErrors(object $block, int $i, int $form, bool $inSuccess, callable $at, array &$seenNames, array &$seenRoles, array &$questions): array
    {
        $errors = [];
        $name = $block->name ?? null;
        if (!is_string($name) || !preg_match(self::NAME_PATTERN, $name)) {
            $errors[] = ['path' => $at($i, 'name'), 'message' => 'name obligatoire : une minuscule, puis minuscules, chiffres ou _ (40 caractères au plus)'];
        } elseif (isset($seenNames[$form][$name])) {
            $errors[] = ['path' => $at($i, 'name'), 'message' => sprintf('name « %s » déjà utilisé dans ce formulaire', $name)];
        } else {
            $seenNames[$form][$name] = true;
        }
        if (!$inSuccess && ($questions[$form] = ($questions[$form] ?? 0) + 1) === self::MAX_QUESTIONS + 1) {
            $errors[] = ['path' => $at($i, 'name'), 'message' => sprintf('%d questions au plus par formulaire', self::MAX_QUESTIONS)];
        }

        $fieldType = $block->fieldType ?? 'text';
        $role = $block->fieldRole ?? null;
        if (is_string($role)) {
            if (isset($seenRoles[$form][$role])) {
                $errors[] = ['path' => $at($i, 'fieldRole'), 'message' => sprintf('fieldRole « %s » déjà utilisé dans ce formulaire', $role)];
            }
            $seenRoles[$form][$role] = true;
            if (isset(self::ROLE_TYPES[$role]) && !in_array($fieldType, self::ROLE_TYPES[$role], true)) {
                $errors[] = ['path' => $at($i, 'fieldType'), 'message' => sprintf('fieldRole « %s » exige fieldType %s', $role, implode(' ou ', self::ROLE_TYPES[$role]))];
            }
        }

        // 11. Bornes cohérentes (28/10/2026) : minimum ≤ maximum, erreur sur le minimum
        foreach (['minLength' => 'maxLength', 'minValue' => 'maxValue', 'minDays' => 'maxDays'] as $min => $max) {
            if (is_numeric($block->$min ?? null) && is_numeric($block->$max ?? null) && $block->$min > $block->$max) {
                $errors[] = ['path' => $at($i, $min), 'message' => sprintf('%s (%s) dépasse %s (%s)', $min, $block->$min, $max, $block->$max)];
            }
        }

        $hasOptions = is_array($block->options ?? null) && $block->options !== [];
        $hasSource = isset($block->optionsSource);
        if (in_array($fieldType, self::CHOICE_TYPES, true)) {
            if (!$hasOptions && !$hasSource) {
                $errors[] = ['path' => $at($i, 'options'), 'message' => sprintf('fieldType %s exige options (au moins une) ou optionsSource', $fieldType)];
            }
        } else {
            if (property_exists($block, 'options') && $block->options !== null) {
                $errors[] = ['path' => $at($i, 'options'), 'message' => 'options seulement sur fieldType select ou choice'];
            }
            if ($hasSource) {
                $errors[] = ['path' => $at($i, 'optionsSource'), 'message' => 'optionsSource seulement sur fieldType select ou choice'];
            }
        }

        return $errors;
    }

    /** @return list<int> index des ancêtres, du parent à la racine (boucles et profondeur bornées) */
    private static function ancestors(object $block, array $blocks, array $byId): array
    {
        $ancestors = [];
        $seen = [];
        $current = is_string($block->parentId ?? null) ? $block->parentId : null;
        while ($current !== null && isset($byId[$current]) && !isset($seen[$current]) && count($ancestors) < self::MAX_ANCESTORS) {
            $seen[$current] = true;
            $ancestors[] = $byId[$current];
            $parent = $blocks[$byId[$current]];
            $current = is_object($parent) && is_string($parent->parentId ?? null) ? $parent->parentId : null;
        }

        return $ancestors;
    }

    /** Blocs qui n'ont de sens que dans un formulaire (pour signaler un field ou un bouton submit sans container steps) */
    private static function usesFormBlocks(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (is_object($block) && (in_array($block->type ?? null, ['field', 'summary'], true)
                || (($block->type ?? null) === 'button' && in_array($block->action ?? null, self::FORM_ACTIONS, true))
                || (property_exists($block, 'stepRole') && $block->stepRole !== null))) {
                return true;
            }
        }

        return false;
    }
}
