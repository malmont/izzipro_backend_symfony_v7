<?php

namespace App\Services\FormSubmissionService;

use App\Entity\FormSubmission;

/**
 * Export CSV des demandes reçues, lisible par Excel : UTF-8 avec BOM, séparateur « ; », une colonne par question
 * (dans l'ordre de première apparition, en-tête = libellé). Une cellule qui commence par = + - @ (ou une tabulation,
 * un retour chariot) est préfixée d'une apostrophe : sinon le tableur l'exécute comme une formule.
 */
final class FormSubmissionCsvWriter
{
    public const STATUS_LABELS = ['new' => 'Nouvelle', 'read' => 'Lue', 'done' => 'Traitée', 'archived' => 'Archivée'];

    /** @param list<FormSubmission> $submissions */
    public static function write(array $submissions, \DateTimeZone $zone): string
    {
        $questions = [];
        foreach ($submissions as $submission) {
            foreach ($submission->getAnswers() as $answer) {
                $name = (string) ($answer['name'] ?? '');
                if ($name !== '' && !isset($questions[$name])) {
                    $questions[$name] = (string) ($answer['label'] ?? $name);
                }
            }
        }

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map([self::class, 'cell'], ['Référence', 'Reçue le', 'Formulaire', 'Statut', 'Cible', 'Langue', ...array_values($questions)]), ';');
        foreach ($submissions as $submission) {
            $byName = [];
            foreach ($submission->getAnswers() as $answer) {
                $byName[(string) ($answer['name'] ?? '')] = (string) ($answer['display'] ?? '');
            }
            fputcsv($out, array_map([self::class, 'cell'], [
                $submission->getReference(),
                $submission->getCreatedAt()->setTimezone($zone)->format('Y-m-d H:i'),
                $submission->getFormName(),
                self::STATUS_LABELS[$submission->getStatus()] ?? $submission->getStatus(),
                $submission->getTarget(),
                $submission->getLocale(),
                ...array_map(fn (string $name) => $byName[$name] ?? '', array_keys($questions)),
            ]), ';');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    public static function cell(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && preg_match('/^[=+\-@\t\r]/', $text) ? "'" . $text : $text;
    }
}
