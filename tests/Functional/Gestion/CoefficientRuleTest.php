<?php

namespace App\Tests\Functional\Gestion;

use App\Gestion\Pricing\CoefficientRule;
use PHPUnit\Framework\TestCase;

/**
 * Règle du coefficient proposé à partir des semblables (validée par le propriétaire le 13/11/2026, lot IA-ACT-5) : chaque ligne de la
 * règle, ses frontières, le plancher, le nombre minimal de semblables.
 */
class CoefficientRuleTest extends TestCase
{
    public function testEachLineOfTheRuleItsBoundariesTheFloorAndTheMinimumOfSimilarItems(): void
    {
        $propose = fn (float $soldShare, float $discountRate, float $displayed = 2.5, float $obtained = 2.1, int $similar = 8, float $min = 1.6): ?array => CoefficientRule::propose($similar, $displayed, $obtained, $soldShare, $discountRate, $min);
        $short = fn (?array $p): ?array => $p === null ? null : [$p['coefficient'], $p['adjustment']];
        // 70 % vendus ou plus, sans rabais : affiché + 0,2
        $this->assertSame([2.7, 'up'], $short($propose(0.85, 0.0)));
        $this->assertSame([2.7, 'up'], $short($propose(0.70, 0.0)), '70 % pile compte comme bien vendu');
        // 70 % vendus ou plus, avec des rabais : le coefficient obtenu, sans autre ajustement
        $this->assertSame([2.1, 'obtained'], $short($propose(0.85, 0.12)));
        // 40 % à 70 % : affiché, inchangé, qu'il y ait eu des rabais ou non
        $this->assertSame([[2.5, 'keep'], [2.5, 'keep'], [2.5, 'keep'], [2.5, 'keep']], [$short($propose(0.55, 0.0)), $short($propose(0.55, 0.2)), $short($propose(0.40, 0.0)), $short($propose(0.6999, 0.0))]);
        // moins de 40 % : obtenu s'il y a eu des rabais (jamais pénalisé deux fois), sinon affiché − 0,2
        $this->assertSame([[2.1, 'obtained'], [2.3, 'down'], [2.3, 'down']], [$short($propose(0.20, 0.15)), $short($propose(0.20, 0.0)), $short($propose(0.3999, 0.0))]);
        // « avec rabais » à partir de 5 % de la valeur au prix affiché ; en dessous (quelques unités soldées), l'article bien vendu garde son +0,2
        $this->assertSame([[2.7, 'up', false], [2.1, 'obtained', true]], [[...$short($propose(0.9, 0.049)), $propose(0.9, 0.049)['discounted']], [...$short($propose(0.9, 0.05)), $propose(0.9, 0.05)['discounted']]]);
        // plancher : jamais sous le coefficient minimum du site
        $this->assertSame([['coefficient' => 1.6, 'adjustment' => 'down', 'discounted' => false, 'floored' => true], ['coefficient' => 1.6, 'adjustment' => 'obtained', 'discounted' => true, 'floored' => true]],
            [$propose(0.1, 0.0, 1.7, 1.2), $propose(0.9, 0.3, 2.0, 1.4)]);
        $this->assertSame([1.5, false], [$propose(0.1, 0.0, 1.7, 1.2, 8, 1.2)['coefficient'], $propose(0.1, 0.0, 1.7, 1.2, 8, 1.2)['floored']], 'plancher réglable par site : 1,2 laisse passer 1,5');
        // au moins 5 semblables, et un coefficient affiché connu
        $this->assertSame([null, [2.7, 'up'], null], [$propose(0.9, 0.0, 2.5, 2.1, 4), $short($propose(0.9, 0.0, 2.5, 2.1, 5)), $propose(0.9, 0.0, 0.0, 0.0)]);
        // les constantes de la fiche
        $this->assertSame([0.70, 0.40, 0.2, 5, 0.05, 0.1], [CoefficientRule::SOLD_WELL, CoefficientRule::SOLD_POORLY, CoefficientRule::STEP, CoefficientRule::MIN_SIMILAR, CoefficientRule::DISCOUNT_FROM, CoefficientRule::KEEP_UNDER]);
        // verdict d'un article déjà en vente : un écart de moins d'un demi-cran ne vaut pas un changement de prix
        $this->assertSame(['keep', 'keep', 'raise', 'lower', 'raise', 'lower', 'keep'], [CoefficientRule::verdict(2.5, 2.5), CoefficientRule::verdict(2.5, 2.59), CoefficientRule::verdict(2.5, 2.7), CoefficientRule::verdict(2.5, 2.1),
            CoefficientRule::verdict(2.5, 2.6), CoefficientRule::verdict(2.5, 2.4), CoefficientRule::verdict(2.5, 2.41)], 'un écart de 0,1 pile vaut un changement, dans les deux sens');
    }
}
