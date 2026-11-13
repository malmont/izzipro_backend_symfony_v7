<?php

namespace App\Tests\Functional\Gestion;

use App\Gestion\Pricing\PriceRounding;
use PHPUnit\Framework\TestCase;

/**
 * Arrondi d'un prix calculé (lot IA-ACT-5, 13/11/2026) : chaque mode, à chaque frontière (9,99 ; 99,99 ; 100), toujours vers le haut.
 */
class PriceRoundingTest extends TestCase
{
    public function testEachModeRoundsUpAtEveryBoundary(): void
    {
        $psy = fn (int $c): int => PriceRounding::apply($c, PriceRounding::PSY_99);
        // sous 100 : la terminaison ,99 supérieure ; un prix déjà en ,99 ne bouge pas
        $this->assertSame([5499, 999, 999, 1099, 9999, 9999, 99, 99], [$psy(5478), $psy(999), $psy(900), $psy(1000), $psy(9999), $psy(9901), $psy(1), $psy(99)]);
        // à partir de 100 : le prochain entier finissant par 9, sans cents ; un prix déjà sur un tel entier ne bouge pas
        $this->assertSame([10900, 15900, 10900, 11900, 10900, 19900, 100900, 20900], [$psy(10000), $psy(15478), $psy(10900), $psy(10901), $psy(10001), $psy(19000), $psy(100001), $psy(19901)]);
        $this->assertSame(10000, PriceRounding::PSY_INTEGER_FROM);
        // ,95 ; entier ; aucun
        $this->assertSame([5495, 5595, 995, 10095, 15495], array_map(fn (int $c) => PriceRounding::apply($c, PriceRounding::ENDS_95), [5478, 5496, 995, 10000, 15478]));
        $this->assertSame([5500, 5400, 100, 15500], array_map(fn (int $c) => PriceRounding::apply($c, PriceRounding::INTEGER), [5478, 5400, 1, 15478]));
        $this->assertSame([5478, 15478], array_map(fn (int $c) => PriceRounding::apply($c, PriceRounding::NONE), [5478, 15478]));
        // jamais vers le bas, quel que soit le mode : la marge du coefficient est toujours tenue
        foreach (PriceRounding::MODES as $mode) {
            foreach ([1, 50, 99, 100, 999, 1000, 5478, 9998, 9999, 10000, 10001, 10899, 10900, 10901, 15478, 99999, 100000, 123456] as $cents) {
                $this->assertGreaterThanOrEqual($cents, PriceRounding::apply($cents, $mode), "$mode $cents");
                $this->assertSame(PriceRounding::apply($cents, $mode), PriceRounding::apply(PriceRounding::apply($cents, $mode), $mode), "$mode $cents : arrondir deux fois ne change rien");
            }
        }
        // un montant nul ou négatif, un mode inconnu : inchangé
        $this->assertSame([0, -5, 5478], [PriceRounding::apply(0, PriceRounding::PSY_99), PriceRounding::apply(-5, PriceRounding::INTEGER), PriceRounding::apply(5478, 'pigeon')]);
        $this->assertSame(['psy_99', 'ends_95', 'integer', 'none'], PriceRounding::MODES);
        $this->assertSame('psy_99', PriceRounding::DEFAULT);
    }
}
