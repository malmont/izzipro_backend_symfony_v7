<?php

namespace App\Services;

/**
 * Code court d'un site (31/10/2026) : 6 caractères de l'alphabet Crockford base32 (0-9 et A-Z sans I, L, O, U),
 * tirés au hasard, uniques sur tous les sites (colonne `tenants.short_code` de la base maître, stockée sans tiret
 * en majuscules), affichés « ABC-D4F ». Le client les tape dans l'application mobile (GET /api/tenant/check-mobile).
 * Règles pures, sans base : la persistance est dans TenantConnectionManager.
 */
final class TenantShortCode
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    public const LENGTH = 6;
    /** Code permanent du site de test : il sert aux relecteurs d'Apple et de Google */
    public const FIXED = ['demo' => 'DEM042'];

    /** Tirage aléatoire (jamais une suite) */
    public static function generate(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /** Code stocké valide : 6 caractères de l'alphabet, majuscules */
    public static function isValid(string $code): bool
    {
        return \strlen($code) === self::LENGTH && strspn($code, self::ALPHABET) === self::LENGTH;
    }

    /**
     * Saisie du client → code stocké, ou null si ce n'est pas un code court : majuscules, espaces et tirets ignorés,
     * I et L lus 1, O lu 0 (Crockford) ; U ou tout autre caractère hors alphabet → null (le site répond alors 404).
     */
    public static function normalize(string $input): ?string
    {
        $code = strtr(strtoupper(preg_replace('/[\s-]+/', '', $input)), ['I' => '1', 'L' => '1', 'O' => '0']);

        return self::isValid($code) ? $code : null;
    }

    /** Affichage « ABC-D4F » */
    public static function format(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return substr($code, 0, 3) . '-' . substr($code, 3);
    }
}
