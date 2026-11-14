<?php

namespace App\LossPrevention\Services;

/**
 * Catalogue des règles (lot L1-a : vague 1, `docs/prevention-pertes.md` § 4) : codes stables, paramètres typés avec leurs bornes et leurs
 * valeurs par défaut (validées par le propriétaire le 14/11/2026, réglables par site). Libellés et descriptions : `translations/pertes.*.yaml`.
 * `person` : la variante « par personne » d'une règle (actes d'un même compte dans le temps), qui a son propre interrupteur.
 * Vague 2 (lot L1-b) : REFUND_PATTERN, DISCOUNT_PATTERN, PRICE_OVERRIDE, APPROVAL_PATTERN.
 */
final class LossRuleCatalog
{
    public const CASH_DISCREPANCY = 'CASH_DISCREPANCY';
    public const DRAWER_NO_SALE = 'DRAWER_NO_SALE';
    public const VOID_AFTER_SALE = 'VOID_AFTER_SALE';

    /** Types de paramètre : l'écran sait comment saisir et afficher */
    public const COUNT = 'count';
    public const CENTS = 'cents';
    public const MINUTES = 'minutes';
    public const DAYS = 'days';

    /** @var array<string, array{wave: int, params: array<string, array{type: string, default: int, min: int, max: int}>, person: ?array<string, array{type: string, default: int, min: int, max: int}>}> */
    public const RULES = [
        self::CASH_DISCREPANCY => [
            'wave' => 1,
            // plancher en plus de l'écart toléré du site (0 par défaut : sans plancher, chaque cent d'écart ouvrirait un constat)
            'params' => ['minAmount' => ['type' => self::CENTS, 'default' => 500, 'min' => 0, 'max' => 1000000]],
            'person' => ['count' => ['type' => self::COUNT, 'default' => 3, 'min' => 2, 'max' => 20], 'days' => ['type' => self::DAYS, 'default' => 30, 'min' => 7, 'max' => 90]],
        ],
        self::DRAWER_NO_SALE => [
            'wave' => 1,
            // constat au-delà de ce nombre d'ouvertures sans vente dans une session
            'params' => ['maxPerSession' => ['type' => self::COUNT, 'default' => 3, 'min' => 0, 'max' => 50]],
            'person' => null,
        ],
        self::VOID_AFTER_SALE => [
            'wave' => 1,
            'params' => [
                'minutes' => ['type' => self::MINUTES, 'default' => 10, 'min' => 1, 'max' => 240], // annulation rapide d'une vente qui a une part en espèces
                'perSession' => ['type' => self::COUNT, 'default' => 3, 'min' => 2, 'max' => 50], // constat à partir de ce nombre d'annulations dans une session
            ],
            'person' => null,
        ],
    ];

    public static function exists(string $code): bool
    {
        return isset(self::RULES[$code]);
    }

    /** @return array<string, int> valeurs par défaut des paramètres d'une règle (`person` : ceux de sa variante par personne) */
    public static function defaults(string $code, bool $person = false): array
    {
        return array_map(fn (array $p) => $p['default'], ($person ? self::RULES[$code]['person'] : self::RULES[$code]['params']) ?? []);
    }
}
