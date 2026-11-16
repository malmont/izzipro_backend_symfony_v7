<?php

namespace App\Services\Storefront;

/**
 * Un texte légal de la fiche (conditions de vente, mentions légales, politique de confidentialité) est-il rempli dans chaque langue que
 * le site sert ? Correctif du 16/11/2026 : avant, une ligne remplie dans N'IMPORTE QUELLE langue suffisait, alors que le site public
 * n'affiche pas le français quand la ligne de la langue demandée existe mais est vide.
 *
 * Langues vérifiées : la langue du site (`entreprise.locale`, français à défaut) et chaque langue qui a une ligne de traduction. Le texte
 * lu pour une langue est celui que le site public affiche (`Entreprise::getTranslation`, `EntrepriseDto`) : la ligne de cette langue ;
 * sans ligne, celle du français, sinon la première ; et, sans aucune ligne, la colonne de la fiche elle-même.
 */
final class LegalTextLanguages
{
    private const COLUMNS = ['condition_of_use', 'legal_notice', 'privacy_policy'];

    /** @return list<string> les langues où le texte manque (vide : complet) */
    public static function missing(\PDO $pdo, string $column): array
    {
        if (!in_array($column, self::COLUMNS, true)) {
            throw new \InvalidArgumentException('texte légal inconnu : ' . $column);
        }
        $rows = $pdo->query("SELECT LOWER(language) AS language, COALESCE(TRIM($column), '') AS text FROM entreprise_translation ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $sheet = $pdo->query("SELECT LOWER(COALESCE(NULLIF(TRIM(locale), ''), 'fr')) AS locale, COALESCE(TRIM($column), '') AS text FROM entreprise ORDER BY id LIMIT 1")->fetch(\PDO::FETCH_ASSOC) ?: ['locale' => 'fr', 'text' => ''];
        $byLanguage = [];
        foreach ($rows as $row) {
            $byLanguage[(string) $row['language']] ??= (string) $row['text'];
        }
        $languages = array_values(array_unique([substr((string) $sheet['locale'], 0, 2), ...array_keys($byLanguage)]));
        $missing = [];
        foreach ($languages as $language) {
            $text = $byLanguage[$language] ?? $byLanguage['fr'] ?? ($rows !== [] ? (string) $rows[0]['text'] : (string) $sheet['text']);
            if ($text === '') {
                $missing[] = $language;
            }
        }

        return $missing;
    }
}
