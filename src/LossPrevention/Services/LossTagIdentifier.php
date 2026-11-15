<?php

namespace App\LossPrevention\Services;

/**
 * Identifiant d'une puce (lot L2-a, `docs/prevention-pertes.md` § 13) : règles pures, sans base.
 *
 * - Forme canonique d'une vraie puce : ce que le lecteur a rendu, sans espaces ni séparateurs (`:`, `-`, `.`), sans préfixe `0x`, en
 *   majuscules ; de l'hexadécimal seulement. NFC : 4, 7, 8 ou 10 octets (8, 14, 16 ou 20 caractères). UHF : un code de 16 à 64
 *   caractères, par mots de 16 bits (multiple de 4 caractères ; 24 pour le code de 96 bits le plus courant).
 * - Puce simulée (mode exercice, démonstration, tests) : `SIM-` suivi de 12 caractères hexadécimaux, fabriquée par le serveur. Aucun
 *   lecteur ne rend cette forme (elle n'est pas de l'hexadécimal) : une vraie puce et une puce simulée ne se confondent jamais.
 * La base n'impose que la forme canonique générale (majuscules, sans espace autour) et l'unicité.
 */
final class LossTagIdentifier
{
    public const UHF = 'uhf';
    public const NFC = 'nfc';
    public const SIMULATED = 'simulated';
    /** Technologies d'une vraie puce */
    public const REAL = [self::UHF, self::NFC];
    public const RAW_MAX = 128;

    /** Forme canonique d'une lecture pour cette technologie, ou null si la lecture n'a pas la forme attendue */
    public static function canonical(mixed $raw, string $technology): ?string
    {
        if (!is_string($raw) || strlen($raw) > self::RAW_MAX) {
            return null;
        }
        if ($technology === self::SIMULATED) {
            $code = strtoupper(trim($raw));

            return preg_match('/\ASIM-[0-9A-F]{12}\z/', $code) === 1 ? $code : null;
        }
        $code = strtoupper((string) preg_replace('/[\s:.\-]+/', '', $raw));
        if (str_starts_with($code, '0X')) {
            $code = substr($code, 2);
        }
        if ($code === '' || preg_match('/\A[0-9A-F]+\z/', $code) !== 1) {
            return null;
        }
        $length = strlen($code);

        return match ($technology) {
            self::NFC => in_array($length, [8, 14, 16, 20], true) ? $code : null,
            self::UHF => $length >= 16 && $length <= 64 && $length % 4 === 0 ? $code : null,
            default => null,
        };
    }

    /**
     * Forme canonique d'une lecture de vraie puce dont on ne connaît pas la technologie (recherche) : NFC, sinon UHF ; null si ce
     * n'est ni l'un ni l'autre. Une puce simulée n'est jamais une lecture valide ici.
     */
    public static function loose(mixed $raw): ?string
    {
        return self::canonical($raw, self::NFC) ?? self::canonical($raw, self::UHF);
    }

    /** Un identifiant de puce simulée, au hasard */
    public static function simulated(): string
    {
        return 'SIM-' . strtoupper(bin2hex(random_bytes(6)));
    }
}
