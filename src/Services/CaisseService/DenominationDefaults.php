<?php

namespace App\Services\CaisseService;

/**
 * Coupures par défaut d'une devise (F18 partie 1, 01/11/2026), créées une fois pour un site qui n'en a aucune dans sa devise :
 * à l'enregistrement de la devise dans la fiche Entreprise, par la commande app:pos:denominations-create ou le bouton EasyAdmin
 * « Créer les coupures de la devise ». Mêmes libellés que les coupures CAD d'origine (« 20 $ », « 25 ¢ »). Les coupures rares
 * sont créées inactives (50 ¢ et 1 $ américains, comme le 1 ¢ canadien) : le site les active s'il les compte.
 */
final class DenominationDefaults
{
    /** @var array<string, list<array{label: string, valueCents: int, kind: string, active: bool}>> */
    private const LISTS = [
        'CAD' => [
            ['100 $', 10000, 'bill', true], ['50 $', 5000, 'bill', true], ['20 $', 2000, 'bill', true], ['10 $', 1000, 'bill', true], ['5 $', 500, 'bill', true],
            ['2 $', 200, 'coin', true], ['1 $', 100, 'coin', true], ['25 ¢', 25, 'coin', true], ['10 ¢', 10, 'coin', true], ['5 ¢', 5, 'coin', true], ['1 ¢', 1, 'coin', false],
        ],
        'EUR' => [
            ['200 €', 20000, 'bill', true], ['100 €', 10000, 'bill', true], ['50 €', 5000, 'bill', true], ['20 €', 2000, 'bill', true], ['10 €', 1000, 'bill', true], ['5 €', 500, 'bill', true],
            ['2 €', 200, 'coin', true], ['1 €', 100, 'coin', true], ['50 ¢', 50, 'coin', true], ['20 ¢', 20, 'coin', true], ['10 ¢', 10, 'coin', true], ['5 ¢', 5, 'coin', true], ['2 ¢', 2, 'coin', true], ['1 ¢', 1, 'coin', true],
        ],
        'USD' => [
            ['100 $', 10000, 'bill', true], ['50 $', 5000, 'bill', true], ['20 $', 2000, 'bill', true], ['10 $', 1000, 'bill', true], ['5 $', 500, 'bill', true], ['2 $', 200, 'bill', true], ['1 $', 100, 'bill', true],
            ['1 $', 100, 'coin', false], ['50 ¢', 50, 'coin', false], ['25 ¢', 25, 'coin', true], ['10 ¢', 10, 'coin', true], ['5 ¢', 5, 'coin', true], ['1 ¢', 1, 'coin', true],
        ],
    ];

    /** Devises qui ont une liste par défaut */
    public static function currencies(): array
    {
        return array_keys(self::LISTS);
    }

    public static function has(string $currency): bool
    {
        return isset(self::LISTS[strtoupper($currency)]);
    }

    /** @return list<array{label: string, valueCents: int, kind: string, active: bool, position: int}> dans l'ordre d'affichage (position à partir de 1) */
    public static function for(string $currency): array
    {
        $out = [];
        foreach (self::LISTS[strtoupper($currency)] ?? [] as $i => [$label, $cents, $kind, $active]) {
            $out[] = ['label' => $label, 'valueCents' => $cents, 'kind' => $kind, 'active' => $active, 'position' => $i + 1];
        }

        return $out;
    }
}
