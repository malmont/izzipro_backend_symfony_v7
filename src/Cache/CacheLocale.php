<?php

namespace App\Cache;

/**
 * Langue ramenée aux langues du site avant toute clé de cache (lot 2, 28/10/2026). Les traductions en base sont `fr`
 * et `en` sur tous les sites ; une valeur libre (`?locale=xx`) créait une entrée de cache par valeur inventée, sans
 * limite. Une langue inconnue vaut `fr`, la langue de repli des traductions.
 */
final class CacheLocale
{
    public const LOCALES = ['fr', 'en'];
    public const DEFAULT = 'fr';

    public static function normalize(?string $locale): string
    {
        $locale = strtolower(substr(trim((string) $locale), 0, 2));

        return in_array($locale, self::LOCALES, true) ? $locale : self::DEFAULT;
    }
}
