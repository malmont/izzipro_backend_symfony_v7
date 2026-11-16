<?php

namespace App\LossPrevention\Services;

/**
 * L'identifiant de 96 bits que la plateforme écrit dans une puce UHF (lot L3-c, `docs/prevention-pertes.md` § 15 et § 16.20) :
 * règles pures, sans base. TOUT le découpage vit ici, pour être arrêté à l'essai du matériel sans rien changer ailleurs.
 *
 * Douze octets, soit 24 caractères hexadécimaux (la forme canonique UHF de `LossTagIdentifier`) :
 * - 2 octets : la marque fixe de la plateforme ;
 * - 4 octets : le site (une empreinte de son code) ;
 * - 4 octets : le numéro de la pièce ;
 * - 1 octet : l'exemplaire (0 pour la première étiquette de la pièce, 1 pour la suivante… : une pièce réétiquetée ne reçoit pas
 *   deux fois le même identifiant) ;
 * - 1 octet : une clé de contrôle des onze premiers (elle détecte une lecture abîmée ; ce n'est pas un secret).
 *
 * Pas de numérotation GS1 ni de code de fabricant : la boutique n'a pas de préfixe d'entreprise GS1.
 */
final class LossTagEpc
{
    /** Marque fixe de la plateforme, en tête de tout identifiant qu'elle écrit */
    public const MARK = 'F1A7';
    public const LENGTH = 24;
    /** Exemplaires d'une même pièce au plus (un octet) */
    public const MAX_COPY = 255;
    public const MAX_NUMBER = 4294967295;

    /** L'identifiant de l'exemplaire `$copy` de la pièce numéro `$number` du site `$siteCode` */
    public static function make(string $siteCode, int $number, int $copy): string
    {
        if ($siteCode === '' || $number < 1 || $number > self::MAX_NUMBER || $copy < 0 || $copy > self::MAX_COPY) {
            throw new \InvalidArgumentException('Identifiant de puce impossible : site, numéro de pièce ou exemplaire hors bornes.');
        }
        $body = self::MARK . self::site($siteCode) . sprintf('%08X%02X', $number, $copy);

        return $body . self::check($body);
    }

    /** Cet identifiant a-t-il été fabriqué par la plateforme pour ce site (marque, site, clé de contrôle) ? */
    public static function isOurs(string $identifier, string $siteCode): bool
    {
        return strlen($identifier) === self::LENGTH && preg_match('/\A[0-9A-F]+\z/', $identifier) === 1
            && str_starts_with($identifier, self::MARK . self::site($siteCode)) && substr($identifier, -2) === self::check(substr($identifier, 0, -2));
    }

    private static function site(string $siteCode): string
    {
        return strtoupper(substr(hash('sha256', 'loss-epc-site:' . $siteCode), 0, 8));
    }

    private static function check(string $body): string
    {
        return strtoupper(substr(hash('sha256', $body), 0, 2));
    }
}
