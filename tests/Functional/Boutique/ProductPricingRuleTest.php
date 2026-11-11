<?php

namespace App\Tests\Functional\Boutique;

use App\Dto\ProductCommerceDto;
use App\Entity\Product;
use PHPUnit\Framework\TestCase;

/**
 * Fiche produit (`pricing`) alignée sur la vente (11/11/2026, écart relevé au lot VAR) : `Product::getEffectivePrice` ignore un prix promotionnel
 * nul ou négatif ; la fiche annonçait pourtant `special { amount: 0, active: true }` et `amount: 0` alors que la caisse et le devis vendaient au
 * prix normal. `amount` de la fiche = prix réellement vendu, dans tous les cas.
 */
class ProductPricingRuleTest extends TestCase
{
    public function testPricingAmountIsAlwaysThePriceActuallySold(): void
    {
        $active = [new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+1 day')];
        foreach ([
            'promotion à 0, en cours' => [0.0, $active, null, 2900],
            'promotion en cours' => [1900.0, $active, 1900, 1900],
            'promotion passée' => [1900.0, [new \DateTimeImmutable('-3 days'), new \DateTimeImmutable('-2 days')], 1900, 2900],
            'sans promotion' => [null, [null, null], null, 2900],
        ] as $case => [$special, [$from, $to], $specialAmount, $amount]) {
            $product = (new Product())->setPrice(2900)->setSpecialPrice($special)->setSpecialPriceFrom($from)->setSpecialPriceTo($to);
            $pricing = ProductCommerceDto::pricing($product);
            $this->assertSame([$specialAmount, $amount], [$pricing['special']['amount'] ?? null, $pricing['amount']], $case);
            $this->assertSame((int) round($product->getEffectivePrice()), $pricing['amount'], "$case : même prix que la vente");
        }
    }
}
