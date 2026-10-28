<?php

namespace App\Services\FormSubmissionService;

use App\Entity\BoutiqueSetting;
use App\Entity\LandingPageSetting;
use App\Services\LandingPageSettingsService\ReglableFormRules;
use App\Services\TenantEntityManagerProvider;

/**
 * Retrouve un formulaire à étapes dans les réglages ENREGISTRÉS du site (le navigateur ne fait jamais foi) :
 * landing page ou boutique (pages système comprises : ce sont des onglets), section d'onglet portant l'uid, container
 * layout « steps » dont l'id est formId. Pas de formulaire dans la navbar ni le footer (règle du validateur).
 * Environ 115 Ko de JSON lus et décodés par envoi : pas de cache.
 */
final class FormDefinitionLocator
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    public function find(string $app, string $sectionUid, string $formId, string $locale): ?FormDefinition
    {
        $configuration = $this->configuration($app);
        foreach (is_object($configuration) && is_array($configuration->tabs ?? null) ? $configuration->tabs : [] as $tab) {
            foreach (is_object($tab) && is_array($tab->sections ?? null) ? $tab->sections : [] as $section) {
                if (!is_object($section) || ($section->uid ?? null) !== $sectionUid || ($section->componentTypeKey ?? null) !== 'typeReglable'
                    || !is_object($section->reglableConfig ?? null) || !is_array($section->reglableConfig->blocks ?? null)) {
                    continue;
                }

                return $this->definition($app, $sectionUid, $formId, $section->reglableConfig->blocks, $locale);
            }
        }

        return null;
    }

    private function definition(string $app, string $sectionUid, string $formId, array $blocks, string $locale): ?FormDefinition
    {
        $byId = [];
        foreach ($blocks as $i => $block) {
            if (is_object($block) && is_string($block->id ?? null)) {
                $byId[$block->id] ??= $i;
            }
        }
        $form = $byId[$formId] ?? null;
        if ($form === null || !ReglableFormRules::isSteps($blocks[$form])) {
            return null;
        }
        $container = $blocks[$form];

        $fields = [];
        foreach ($blocks as $block) {
            if (!is_object($block) || ($block->type ?? null) !== 'field' || !is_string($block->name ?? null)) {
                continue;
            }
            // étape = ancêtre enfant direct du formulaire ; l'étape success ne contient pas de questions à remplir
            $step = $this->stepOf($block, $blocks, $byId, $formId);
            if ($step === null || ($step->stepRole ?? null) === 'success') {
                continue;
            }
            $fields[] = $this->field($block, $locale);
        }

        $canonical = array_map(fn (array $f) => array_diff_key($f, ['label' => 1, 'options' => 1]) + ['options' => array_column($f['options'], 'value')], $fields);

        return new FormDefinition(
            $app, $sectionUid, $formId,
            mb_substr($this->translated($container, 'formName', $locale) ?: 'Formulaire', 0, 255),
            in_array($container->formTarget ?? null, ['reservation', 'contact'], true) ? $container->formTarget : 'generic',
            $fields,
            hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE))
        );
    }

    private function field(object $block, string $locale): array
    {
        $options = [];
        $translatedLabels = $block->translations->$locale->options ?? null;
        foreach (is_array($block->options ?? null) ? $block->options : [] as $k => $option) {
            if (is_object($option) && (is_string($option->value ?? null) || is_int($option->value ?? null))) {
                $label = is_array($translatedLabels) && is_string($translatedLabels[$k] ?? null) && trim($translatedLabels[$k]) !== '' ? $translatedLabels[$k] : (string) ($option->label ?? $option->value);
                $options[] = ['value' => (string) $option->value, 'label' => $label];
            }
        }
        $number = fn (string $key) => is_int($block->$key ?? null) || is_float($block->$key ?? null) ? $block->$key : null;
        $int = fn (string $key) => is_int($block->$key ?? null) ? $block->$key : null;

        return [
            'name' => $block->name,
            'fieldType' => is_string($block->fieldType ?? null) ? $block->fieldType : 'text',
            'label' => $this->translated($block, 'label', $locale) ?: $block->name,
            'required' => ($block->required ?? false) === true,
            'minLength' => $int('minLength'),
            'maxLength' => $int('maxLength'),
            'minValue' => $number('minValue'),
            'maxValue' => $number('maxValue'),
            'minDays' => $int('minDays'),
            'maxDays' => $int('maxDays'),
            'options' => $options,
            'optionsSource' => is_string($block->optionsSource ?? null) ? $block->optionsSource : null,
            'fieldRole' => is_string($block->fieldRole ?? null) ? $block->fieldRole : null,
        ];
    }

    /** Texte d'une propriété dans la langue demandée (translations.<langue>.<clé>), sinon la valeur de base */
    private function translated(object $block, string $key, string $locale): string
    {
        $value = $block->translations->$locale->$key ?? null;
        if (!is_string($value) || trim($value) === '') {
            $value = $block->$key ?? '';
        }

        return trim(strip_tags(is_string($value) ? $value : ''));
    }

    private function stepOf(object $block, array $blocks, array $byId, string $formId): ?object
    {
        $current = $block;
        for ($depth = 0; $depth < 20; $depth++) {
            $parentId = is_string($current->parentId ?? null) ? $current->parentId : null;
            if ($parentId === null || !isset($byId[$parentId])) {
                return null;
            }
            $parent = $blocks[$byId[$parentId]];
            if ($parentId === $formId) {
                return $current !== $block ? $current : null;
            }
            $current = $parent;
        }

        return null;
    }

    private function configuration(string $app): mixed
    {
        $em = $this->emProvider->getEntityManager();
        $class = $app === 'boutique' ? BoutiqueSetting::class : LandingPageSetting::class;
        $table = $em->getClassMetadata($class)->getTableName();
        $raw = $em->getConnection()->fetchOne("SELECT configuration FROM $table ORDER BY id LIMIT 1");

        return is_string($raw) ? json_decode($raw, false) : null;
    }
}
