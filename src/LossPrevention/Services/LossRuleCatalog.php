<?php

namespace App\LossPrevention\Services;

/**
 * Catalogue des règles (lots L1-a : vague 1, et L1-b : vague 2 ; `docs/prevention-pertes.md` § 4) : codes stables, paramètres typés avec leurs bornes et leurs
 * valeurs par défaut (validées par le propriétaire le 14/11/2026, réglables par site). Libellés et descriptions : `translations/pertes.*.yaml`.
 * `person` : la variante « par personne » d'une règle (actes d'un même compte dans le temps), qui a son propre interrupteur.
 */
final class LossRuleCatalog
{
    public const CASH_DISCREPANCY = 'CASH_DISCREPANCY';
    public const DRAWER_NO_SALE = 'DRAWER_NO_SALE';
    public const VOID_AFTER_SALE = 'VOID_AFTER_SALE';
    public const REFUND_PATTERN = 'REFUND_PATTERN';
    public const DISCOUNT_PATTERN = 'DISCOUNT_PATTERN';
    public const PRICE_OVERRIDE = 'PRICE_OVERRIDE';
    public const APPROVAL_PATTERN = 'APPROVAL_PATTERN';
    /** Lot L3-b2b : articles suivis vendus sans lecture, par poste et par jour (jamais par personne) */
    public const UNREAD_SOLD = 'UNREAD_SOLD';
    /** Lot L4-b : une pièce lue à la sortie qu'aucune vente n'explique, par pièce et par jour (jamais par personne) */
    public const EXIT_WITHOUT_SALE = 'EXIT_WITHOUT_SALE';
    /** Lot L4-b : sorties rattrapées sur un article pas entièrement étiqueté, par article et par semaine (jamais par personne) */
    public const EXIT_COVERED = 'EXIT_COVERED';
    /** Les règles de la sortie : elles ne lisent pas le journal de caisse, mais les lectures des antennes (`LossExitService`) */
    public const EXIT_RULES = [self::EXIT_WITHOUT_SALE, self::EXIT_COVERED];

    /** Types de paramètre : l'écran sait comment saisir et afficher */
    public const COUNT = 'count';
    public const CENTS = 'cents';
    public const MINUTES = 'minutes';
    public const DAYS = 'days';
    public const PERCENT = 'percent';

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
        // Vague 2 (lot L1-b)
        self::REFUND_PATTERN => [
            'wave' => 2,
            // sans paramètre : un retour sans ticket, ou un remboursement en espèces d'une vente payée sans espèces, ouvre toujours un constat
            'params' => [],
            'person' => ['count' => ['type' => self::COUNT, 'default' => 3, 'min' => 2, 'max' => 50]], // retours du même compte dans une journée du magasin
        ],
        self::DISCOUNT_PATTERN => [
            'wave' => 2,
            'params' => ['perSession' => ['type' => self::COUNT, 'default' => 3, 'min' => 2, 'max' => 50]], // ventes remisées au motif « Autre » ou sans motif, dans une session
            'person' => [
                'count' => ['type' => self::COUNT, 'default' => 5, 'min' => 2, 'max' => 50],
                'days' => ['type' => self::DAYS, 'default' => 7, 'min' => 1, 'max' => 30],
                'share' => ['type' => self::PERCENT, 'default' => 90, 'min' => 50, 'max' => 100], // part du plafond du caissier à partir de laquelle une remise compte
            ],
        ],
        self::PRICE_OVERRIDE => [
            'wave' => 2,
            'params' => ['perSession' => ['type' => self::COUNT, 'default' => 3, 'min' => 2, 'max' => 50]], // baisses de prix à la main dans une session
            'person' => null,
        ],
        self::APPROVAL_PATTERN => [
            'wave' => 2,
            // les validations refusées n'ont pas de session au journal de caisse : elles sont comptées par tablette et par journée du magasin
            'params' => ['perDay' => ['type' => self::COUNT, 'default' => 3, 'min' => 2, 'max' => 50]],
            'person' => null,
        ],
        self::UNREAD_SOLD => [
            'wave' => 3,
            // les deux seuils sont exigés : un nombre d'articles sans lecture, et leur part parmi les articles suivis vendus ce jour-là sur ce poste
            'params' => ['minCount' => ['type' => self::COUNT, 'default' => 5, 'min' => 1, 'max' => 500], 'minShare' => ['type' => self::PERCENT, 'default' => 20, 'min' => 1, 'max' => 100]],
            'person' => null,
        ],
        // Vague 4 (lot L4-b) : les antennes de la sortie
        self::EXIT_WITHOUT_SALE => [
            'wave' => 4,
            'params' => [], // un constat par pièce et par journée du magasin, gravité haute
            'person' => null,
        ],
        self::EXIT_COVERED => [
            'wave' => 4,
            // nombre de sorties rattrapées dans la semaine sur un même article à partir duquel la gravité passe de faible à moyenne
            'params' => ['minCount' => ['type' => self::COUNT, 'default' => 3, 'min' => 2, 'max' => 20]],
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
