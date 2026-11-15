<?php

namespace App\LossPrevention\Services;

/**
 * Clé d'un boîtier (lot L3-a) : `IZ-BOX-XXXX-XXXX-XXXX`, alphabet sans caractères ambigus, 60 bits d'aléa. Montrée une seule fois ;
 * la base n'en garde que l'empreinte SHA-256 et un aperçu. Elle vit dans la table du module (`loss_box`), pas dans les clés
 * d'activation de la plateforme : le module n'écrit que dans ses tables, et une clé de boîtier n'active aucune application.
 */
final class LossBoxKey
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function generate(): string
    {
        $random = '';
        for ($i = 0; $i < 12; ++$i) {
            $random .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return sprintf('IZ-BOX-%s-%s-%s', substr($random, 0, 4), substr($random, 4, 4), substr($random, 8, 4));
    }

    /** Forme canonique d'une saisie (minuscules, espaces et tirets tolérés) ; null si ce n'est pas une clé de boîtier */
    public static function canonical(mixed $raw): ?string
    {
        if (!is_string($raw) || strlen($raw) > 64) {
            return null;
        }
        $flat = strtoupper((string) preg_replace('/[\s-]+/', '', $raw));
        if (preg_match('/\AIZBOX([A-Z0-9]{12})\z/', $flat, $m) !== 1) {
            return null;
        }

        return sprintf('IZ-BOX-%s-%s-%s', substr($m[1], 0, 4), substr($m[1], 4, 4), substr($m[1], 8, 4));
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /** Ce qu'on montre d'une clé après sa création : son début */
    public static function hint(string $key): string
    {
        return substr($key, 0, 11) . '-****-****';
    }
}
