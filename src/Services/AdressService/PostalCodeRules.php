<?php

namespace App\Services\AdressService;

/**
 * Règle de code postal par pays (01/11/2026, format international). Canada inchangé (A1A 1A1) ; États-Unis 12345 ou
 * 12345-6789 ; tout autre pays, ou pays non reconnu : 3 à 10 lettres ou chiffres, espaces et tirets admis.
 */
final class PostalCodeRules
{
    public const CANADA = '/^[A-Za-z]\d[A-Za-z][ -]?\d[A-Za-z]\d$/';
    public const USA = '/^\d{5}(-\d{4})?$/';
    public const OTHER = '/^(?=.{3,10}$)[A-Za-z0-9]+([ -][A-Za-z0-9]+)*$/';

    private const CANADA_NAMES = ['ca', 'can', 'canada'];
    private const USA_NAMES = ['us', 'usa', 'unitedstates', 'unitedstatesofamerica', 'etatsunis', 'etatsunisdamerique'];

    /** Message d'erreur, ou null si le code postal convient au pays */
    public static function violation(string $zipCode, string $country): ?string
    {
        $key = strtolower((string) preg_replace('/[^a-z]/i', '', self::ascii($country)));
        $zip = trim($zipCode);
        if (in_array($key, self::CANADA_NAMES, true)) {
            return preg_match(self::CANADA, $zip) ? null : 'Le code postal doit être au format canadien A1A 1A1.';
        }
        if (in_array($key, self::USA_NAMES, true)) {
            return preg_match(self::USA, $zip) ? null : 'Le code postal doit être au format américain 12345 ou 12345-6789.';
        }

        return preg_match(self::OTHER, $zip) ? null : 'Le code postal doit compter de 3 à 10 lettres ou chiffres.';
    }

    private static function ascii(string $value): string
    {
        return class_exists(\Normalizer::class)
            ? (string) preg_replace('/\p{Mn}/u', '', (string) \Normalizer::normalize($value, \Normalizer::FORM_D))
            : $value;
    }
}
