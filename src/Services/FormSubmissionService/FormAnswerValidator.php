<?php

namespace App\Services\FormSubmissionService;

use App\Entity\ServiceOffer;
use App\Services\TenantEntityManagerProvider;

/**
 * Contrôle des réponses d'un formulaire à étapes contre sa définition enregistrée, avec les règles du navigateur
 * (formSteps.js, fieldError) : vide = absente, null, chaîne blanche ou case non cochée ; obligatoire et vide refusé ;
 * courriel, téléphone, date (bornes en jours comptées dans le fuseau du site), nombre (bornes incluses), choix parmi les
 * options ou, avec optionsSource « services », une prestation réservable (serviceOfferId) ; longueurs comptées après
 * suppression des espaces de début et de fin (5 000 caractères par défaut).
 */
final class FormAnswerValidator
{
    public const DEFAULT_MAX_LENGTH = 5000;
    public const EMAIL_PATTERN = '/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/u';
    public const TEL_PATTERN = '/^\+?[\d\s().-]{7,20}$/';
    private const TEXT_TYPES = ['text', 'email', 'tel', 'textarea'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /**
     * @param array<string, mixed> $answers réponses envoyées (clé = name)
     * @return array{0: list<array{name: string, label: string, type: string, value: mixed, display: string}>, 1: list<array{path: string, message: string}>, 2: array<string, mixed>}
     *         réponses enregistrées (toutes les questions, dans l'ordre du formulaire), erreurs, valeurs utiles aux cibles (name => valeur normalisée)
     */
    public function validate(FormDefinition $form, array $answers, \DateTimeZone $siteZone): array
    {
        $stored = [];
        $errors = [];
        $values = [];
        foreach ($form->fields as $field) {
            $name = $field['name'];
            $raw = $answers[$name] ?? null;
            [$value, $display, $error] = $this->check($field, $raw, $siteZone);
            if ($error !== null) {
                $errors[] = ['path' => "answers.$name", 'message' => $error];
            }
            $values[$name] = $value;
            $stored[] = ['name' => $name, 'label' => $field['label'], 'type' => $field['fieldType'], 'value' => $value, 'display' => $display];
        }

        return [$stored, $errors, $values];
    }

    /** @return array{0: mixed, 1: string, 2: ?string} valeur normalisée, texte affiché, erreur */
    private function check(array $field, mixed $raw, \DateTimeZone $siteZone): array
    {
        $type = $field['fieldType'];
        $empty = $raw === null || (is_string($raw) && trim($raw) === '') || ($type === 'checkbox' && $raw === false);
        if ($empty) {
            return [$type === 'checkbox' ? false : null, $type === 'checkbox' ? 'Non' : '', $field['required'] ? 'Cette question est obligatoire.' : null];
        }

        return match (true) {
            $type === 'checkbox' => is_bool($raw) ? [true, 'Oui', null] : [null, '', 'Case à cocher : vrai ou faux attendu.'],
            $type === 'number' => $this->number($field, $raw),
            $type === 'date' => $this->date($field, $raw, $siteZone),
            in_array($type, ['select', 'choice'], true) => $this->choice($field, $raw),
            default => $this->text($field, $raw),
        };
    }

    private function text(array $field, mixed $raw): array
    {
        if (!is_string($raw)) {
            return [null, '', 'Texte attendu.'];
        }
        $value = trim($raw);
        $length = mb_strlen($value);
        if ($field['minLength'] !== null && $length < $field['minLength']) {
            return [$value, $value, sprintf('Au moins %d caractères.', $field['minLength'])];
        }
        $max = $field['maxLength'] ?? self::DEFAULT_MAX_LENGTH;
        if ($length > $max) {
            return [mb_substr($value, 0, $max), mb_substr($value, 0, $max), sprintf('%d caractères au plus.', $max)];
        }
        if ($field['fieldType'] === 'email' && !preg_match(self::EMAIL_PATTERN, $value)) {
            return [$value, $value, 'Adresse courriel invalide.'];
        }
        if ($field['fieldType'] === 'tel' && (!preg_match(self::TEL_PATTERN, $value) || preg_match_all('/\d/', $value) < 7)) {
            return [$value, $value, 'Numéro de téléphone invalide.'];
        }

        return [$value, $value, null];
    }

    private function number(array $field, mixed $raw): array
    {
        if (!is_int($raw) && !is_float($raw)) {
            return [null, '', 'Nombre attendu.'];
        }
        $display = is_float($raw) ? rtrim(rtrim(number_format($raw, 6, ',', ''), '0'), ',') : (string) $raw;
        if ($field['minValue'] !== null && $raw < $field['minValue']) {
            return [$raw, $display, sprintf('Au moins %s.', $field['minValue'])];
        }
        if ($field['maxValue'] !== null && $raw > $field['maxValue']) {
            return [$raw, $display, sprintf('Au plus %s.', $field['maxValue'])];
        }

        return [$raw, $display, null];
    }

    private function date(array $field, mixed $raw, \DateTimeZone $siteZone): array
    {
        $date = is_string($raw) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $siteZone) : false;
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            return [null, '', 'Date attendue au format AAAA-MM-JJ.'];
        }
        $today = new \DateTimeImmutable('today', $siteZone);
        $days = (int) $today->diff($date)->format('%r%a');
        $display = $date->format('d/m/Y');
        if ($field['minDays'] !== null && $days < $field['minDays']) {
            return [$raw, $display, $field['minDays'] === 0 ? 'Date passée.' : sprintf('Date au plus tôt dans %d jour(s).', $field['minDays'])];
        }
        if ($field['maxDays'] !== null && $days > $field['maxDays']) {
            return [$raw, $display, sprintf('Date au plus tard dans %d jour(s).', $field['maxDays'])];
        }

        return [$raw, $display, null];
    }

    private function choice(array $field, mixed $raw): array
    {
        if ($field['optionsSource'] === 'services') {
            $id = is_int($raw) ? $raw : (is_string($raw) && ctype_digit($raw) ? (int) $raw : null);
            $offer = $id !== null ? $this->emProvider->getEntityManager()->getRepository(ServiceOffer::class)->find($id) : null;
            if (!$offer instanceof ServiceOffer || !$offer->isBookable()) {
                return [null, '', 'Prestation indisponible : choisissez-en une autre.'];
            }

            return [$id, (string) $offer->getTitre(), null];
        }
        if (!is_string($raw) && !is_int($raw)) {
            return [null, '', 'Choix invalide.'];
        }
        foreach ($field['options'] as $option) {
            if ($option['value'] === (string) $raw) {
                return [$option['value'], $option['label'], null];
            }
        }

        return [null, '', 'Choix invalide.'];
    }
}
