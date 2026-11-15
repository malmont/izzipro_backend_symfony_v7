<?php

namespace App\Services\BarcodeService;

/**
 * Forme et règles d'un code-barres du catalogue (lot CB-1, 15/11/2026, `docs/codes-barres.md`) : fonctions pures, sans base.
 *
 * - Forme canonique : espaces autour retirés, chaîne vide tenue pour « pas de code », lettres en majuscules. C'est celle que la base
 *   impose elle-même (déclencheur `catalog_barcode_guard`) ; les entités `Product` et `ProductVariant` la prennent dès l'écriture en
 *   mémoire, pour que l'objet et la ligne portent toujours la même valeur (Doctrine ne relit pas la ligne après l'écriture).
 * - Forme acceptée à la saisie : 1 à 64 caractères parmi les lettres sans accent, les chiffres et `- . _ /`, sans espace.
 * - Formes réservées, refusées : le préfixe `IZ-` (documents de la caisse : tickets, avoirs, cartes-cadeaux, fiches clients) et les
 *   deux formes des coupons de fabricant que la caisse sait lire (`CouponBarcodeParser` : 12 chiffres commençant par 5 ; 24 chiffres ou
 *   plus commençant par 8110). Un scan ne doit pas confondre un article avec un ticket ou un coupon.
 * - Code généré : EAN-13 interne, préfixe 29 (usage interne au magasin ; 20 à 28 restent libres pour de futures étiquettes de balance),
 *   numéro sur 10 chiffres, clé de contrôle.
 */
final class CatalogBarcode
{
    public const MAX_LENGTH = 64;
    public const GENERATED_PREFIX = '29';
    public const RESERVED_PREFIX = 'IZ-';

    public const INVALID = 'BARCODE_INVALID';
    public const RESERVED = 'BARCODE_RESERVED';

    /** Forme canonique d'un code saisi ou scanné ; null : pas de code */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $code = mb_strtoupper(trim($raw));

        return $code === '' ? null : $code;
    }

    /** Refus d'un code déjà en forme canonique : `BARCODE_INVALID`, `BARCODE_RESERVED`, ou null s'il est acceptable */
    public static function refusal(string $code): ?string
    {
        if (preg_match('/\A[A-Z0-9._\/-]{1,' . self::MAX_LENGTH . '}\z/', $code) !== 1) {
            return self::INVALID;
        }
        if (str_starts_with($code, self::RESERVED_PREFIX)) {
            return self::RESERVED;
        }
        if (ctype_digit($code) && ((strlen($code) === 12 && $code[0] === '5') || (strlen($code) >= 24 && str_starts_with($code, '8110')))) {
            return self::RESERVED;
        }

        return null;
    }

    /** Code généré de rang `$number` (1 à 9 999 999 999) : 29 + numéro sur 10 chiffres + clé */
    public static function generated(int $number): string
    {
        if ($number < 1 || $number > 9999999999) {
            throw new \OutOfRangeException('Numéro de code-barres généré hors de la plage');
        }
        $body = self::GENERATED_PREFIX . str_pad((string) $number, 10, '0', STR_PAD_LEFT);

        return $body . self::checkDigit($body);
    }

    /** Clé de contrôle d'un EAN-13, calculée sur ses 12 premiers chiffres */
    public static function checkDigit(string $twelveDigits): int
    {
        if (strlen($twelveDigits) !== 12 || !ctype_digit($twelveDigits)) {
            throw new \InvalidArgumentException('Douze chiffres attendus');
        }
        $sum = 0;
        for ($i = 0; $i < 12; ++$i) {
            $sum += (int) $twelveDigits[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }

    /** Vrai pour 13 chiffres dont la clé de contrôle est juste (imprimable en EAN-13 ; sinon le code part en Code 128) */
    public static function isEan13(string $code): bool
    {
        return strlen($code) === 13 && ctype_digit($code) && self::checkDigit(substr($code, 0, 12)) === (int) $code[12];
    }

    /** Vrai pour 13 chiffres dont la clé est fausse : accepté, avec un avertissement à la saisie */
    public static function hasWrongCheckDigit(string $code): bool
    {
        return strlen($code) === 13 && ctype_digit($code) && !self::isEan13($code);
    }
}
