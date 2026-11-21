<?php

namespace App\Services\Privacy;

/**
 * Masquage commun des données personnelles d'un client final (lot DON-1, 18/11/2026, `docs/audit-donnees.md`), pour tout ce qui s'écrit
 * dans un journal qu'on ne pourra plus effacer (journal de caisse inaltérable) ou qui sort du site (incidents de la plateforme, lot
 * INCIDENTS) :
 * - courriel : « m***@domaine » (seul le domaine reste) ;
 * - téléphone : « •••• 1234 » (les 4 derniers chiffres) ;
 * - code (fidélité) : « •••• » et ses 4 derniers caractères, comme une carte-cadeau ;
 * - nom ou texte tapé : sa première lettre suivie de « *** ».
 * Fonctions pures, sans état : la même entrée donne toujours la même sortie.
 */
final class PersonalDataMask
{
    public static function email(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($user, 0, 1) . '***@' . $domain;
    }

    public static function phone(?string $phone): ?string
    {
        return $phone === null ? null : '•••• ' . substr(preg_replace('/\D+/', '', $phone) ?? '', -4);
    }

    public static function code(string $code): string
    {
        return '•••• ' . substr($code, -4);
    }

    /**
     * Un texte libre (message d'erreur, écran, valeur d'un contexte) : courriels masqués, numéros de 7 chiffres ou plus réduits à leurs
     * 4 derniers, jetons (JWT, valeurs de « password », « token », « secret », « authorization »…, longues suites de 32 caractères)
     * remplacés. Un nom ne se reconnaît pas dans un texte libre : les champs d'un nom ne sont jamais acceptés (liste blanche).
     * Espaces réduits, `$max` caractères au plus.
     */
    /** Lot ABO-4 : début du chemin des liens d'agenda, dont la suite est un jeton */
    public const CALENDAR_PREFIX = '/api/calendrier/';

    /** Lot ABO-4 (décision du chef) : `/api/calendrier/{jeton}…` devient `/api/calendrier/***` (journaux, anomalies) */
    public static function calendarPaths(string $text): string
    {
        return (string) preg_replace('~/api/calendrier/[^/?\s"\'<>#]+~', '/api/calendrier/***', $text);
    }

    public static function text(string $text, int $max = 300): string
    {
        $text = self::calendarPaths($text); // lot ABO-4 : le jeton d'un lien d'agenda, jamais dans une anomalie
        $text = (string) preg_replace('/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]+)?/', '[jeton]', $text);
        $text = (string) preg_replace('/\b(bearer|token|password|passwd|pwd|secret|api[_\-]?key|authorization|cookie|pin|otp)\b(\s*[:=]\s*|\s+)(?!\[jeton\])\S+/i', '$1$2[masqué]', $text);
        $text = (string) preg_replace_callback('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', fn (array $m): string => (string) self::email($m[0]), $text);
        $text = (string) preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '[jeton]', $text);
        $text = (string) preg_replace_callback('/\+?\d[\d\s().\-]{5,}\d/', fn (array $m): string => strlen((string) preg_replace('/\D+/', '', $m[0])) >= 7 ? (string) self::phone($m[0]) : $m[0], $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, $max);
    }

    public static function name(string $text): string
    {
        $text = trim($text);

        return $text === '' ? '' : mb_substr($text, 0, 1) . '***';
    }
}
