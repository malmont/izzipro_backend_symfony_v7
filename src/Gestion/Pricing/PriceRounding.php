<?php

namespace App\Gestion\Pricing;

/**
 * Arrondi d'un prix CALCULÉ (coût × coefficient), selon l'usage du commerce (lot IA-ACT-5, 13/11/2026, `docs/prix-gestion.md` § 5).
 * Toujours VERS LE HAUT : la marge n'est jamais sous celle du coefficient. Il porte sur le prix affiché au client, celui que la fiche
 * enregistre (taxes comprises si le site affiche ses prix ainsi, hors taxes sinon) : aucun calcul de taxe ici. Un prix SAISI par le
 * gérant n'est jamais arrondi : ne pas l'envoyer ici.
 *
 * Réglage du site (`GestionPriceSettingsService`), `psy_99` par défaut :
 * - `psy_99` : sous 100, la terminaison ,99 supérieure (54,78 → 54,99 ; 9,99 et 99,99 inchangés) ; à partir de 100, le prochain entier
 *   finissant par 9 (100,00 → 109,00 ; 154,78 → 159,00 ; 109,00 inchangé) ;
 * - `ends_95` : la terminaison ,95 supérieure ; `integer` : l'entier supérieur ; `none` : au cent, sans arrondi.
 */
final class PriceRounding
{
    public const PSY_99 = 'psy_99';
    public const ENDS_95 = 'ends_95';
    public const INTEGER = 'integer';
    public const NONE = 'none';
    public const MODES = [self::PSY_99, self::ENDS_95, self::INTEGER, self::NONE];
    public const DEFAULT = self::PSY_99;

    /** Seuil (en cents) à partir duquel `psy_99` passe de la terminaison ,99 à l'entier finissant par 9 */
    public const PSY_INTEGER_FROM = 10000;

    public static function apply(int $cents, string $mode): int
    {
        if ($cents <= 0) {
            return $cents;
        }

        return match ($mode) {
            self::PSY_99 => $cents < self::PSY_INTEGER_FROM ? self::ending($cents, 99) : self::nextEndingInNine($cents),
            self::ENDS_95 => self::ending($cents, 95),
            self::INTEGER => (int) (ceil($cents / 100) * 100),
            default => $cents,
        };
    }

    /** Le plus petit montant ≥ `$cents` dont les cents valent `$ending` */
    private static function ending(int $cents, int $ending): int
    {
        $candidate = intdiv($cents, 100) * 100 + $ending;

        return $candidate >= $cents ? $candidate : $candidate + 100;
    }

    /** Le plus petit entier ≥ `$cents` (en unités) qui finit par 9, rendu en cents : 100,00 → 109,00 ; 109,01 → 119,00 */
    private static function nextEndingInNine(int $cents): int
    {
        $units = (int) ceil($cents / 100);
        $units += (9 - $units % 10 + 10) % 10;

        return $units * 100;
    }
}
