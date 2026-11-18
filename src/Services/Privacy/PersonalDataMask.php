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

    public static function name(string $text): string
    {
        $text = trim($text);

        return $text === '' ? '' : mb_substr($text, 0, 1) . '***';
    }
}
