<?php

namespace App\Services\FormSubmissionService;

/**
 * Formulaire à étapes tel qu'enregistré dans les réglages du site (lu par FormDefinitionLocator) : seules les questions
 * des étapes à remplir comptent (celles de l'étape success, affichée après l'envoi, ne sont pas des questions).
 * Libellés dans la langue de l'envoi (translations.<langue>), sinon ceux de base.
 */
final class FormDefinition
{
    /**
     * @param list<array{name: string, fieldType: string, label: string, required: bool, minLength: ?int, maxLength: ?int,
     *     minValue: int|float|null, maxValue: int|float|null, minDays: ?int, maxDays: ?int, options: list<array{value: string, label: string}>,
     *     optionsSource: ?string, fieldRole: ?string}> $fields
     */
    public function __construct(
        public readonly string $app,
        public readonly string $sectionUid,
        public readonly string $formId,
        public readonly string $formName,
        public readonly string $target,
        public readonly array $fields,
        public readonly string $hash
    ) {
    }

    /** @return array<string, array> question par name */
    public function byName(): array
    {
        return array_column($this->fields, null, 'name');
    }

    /** Question qui porte ce rôle (service, date, slot, name, phone, email, address, notes, guests), ou null */
    public function byRole(string $role): ?array
    {
        foreach ($this->fields as $field) {
            if ($field['fieldRole'] === $role) {
                return $field;
            }
        }

        return null;
    }
}
