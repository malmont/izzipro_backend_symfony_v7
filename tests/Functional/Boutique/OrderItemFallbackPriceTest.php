<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\Order;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\OrderService\OrderItemService;
use PHPUnit\Framework\TestCase;

/**
 * Repli de OrderItemService::createOrderItem sans prix fourni (11/11/2026, écart relevé au lot VAR) : même règle que le devis, le prix propre de
 * la variante d'abord, sinon le prix effectif de l'article. Prix fourni : inchangé (tous les appelants actuels le fournissent).
 */
class OrderItemFallbackPriceTest extends TestCase
{
    public function testFallbackUsesTheVariantOwnPriceFirst(): void
    {
        $product = (new Product())->setPrice(3500);
        $own = (new ProductVariant())->setProduct($product)->setPrice(3900);
        $plain = (new ProductVariant())->setProduct($product);
        $service = new OrderItemService();
        $this->assertSame([3900.0, 7800.0], [$service->createOrderItem(new Order(), $own, 2)->getUnitPrice(), $service->createOrderItem(new Order(), $own, 2)->getTotalPrice()], 'prix propre de la variante');
        $this->assertSame(3500.0, $service->createOrderItem(new Order(), $plain, 1)->getUnitPrice(), 'sinon prix effectif de l\'article');
        $this->assertSame(1234.0, $service->createOrderItem(new Order(), $own, 1, 1234.0)->getUnitPrice(), 'prix fourni : inchangé');
    }
}
