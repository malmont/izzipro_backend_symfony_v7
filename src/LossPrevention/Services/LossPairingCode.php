<?php

namespace App\LossPrevention\Services;

/**
 * Code de jumelage d'un boîtier (lot L3-a2, `docs/prevention-pertes.md` § 16.16) : `KX7-42P-9QM`, trois groupes de trois
 * caractères, alphabet sans caractères ambigus (ni 0, ni 1, ni I, ni O), 45 bits d'aléa. **C'est le boîtier qui le crée** ; la
 * plateforme n'en garde qu'une empreinte (`LossBoxToken::pairingCodeHash`). Le code seul ne donne rien : chaque appel du boîtier
 * porte aussi la preuve de sa machine.
 */
final class LossPairingCode
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Forme canonique d'une saisie (minuscules, espaces et tirets tolérés) ; null si ce n'est pas un code de jumelage */
    public static function canonical(mixed $raw): ?string
    {
        if (!is_string($raw) || strlen($raw) > 32) {
            return null;
        }
        $flat = strtoupper((string) preg_replace('/[\s-]+/', '', $raw));
        if (preg_match('/\A[A-HJ-NP-Z2-9]{9}\z/', $flat) !== 1) {
            return null;
        }

        return substr($flat, 0, 3) . '-' . substr($flat, 3, 3) . '-' . substr($flat, 6, 3);
    }

    /** Un code tiré au hasard : ce que fait le boîtier (ici pour le boîtier d'essai et les exemples) */
    public static function generate(): string
    {
        $random = '';
        for ($i = 0; $i < 9; ++$i) {
            $random .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($random, 0, 3) . '-' . substr($random, 3, 3) . '-' . substr($random, 6, 3);
    }
}
