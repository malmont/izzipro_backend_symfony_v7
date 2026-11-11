<?php
namespace App\Services\OrderService;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\ProductVariant;

class OrderItemService
{
    public function createOrderItem(Order $order, ProductVariant $productVariant, int $quantity, ?float $unitPrice = null): OrderItems
    {
        $orderItem = new OrderItems();
        $orderItem->setOrderAssociated($order);
        $order->addOrderItem($orderItem);
        $orderItem->setProductVariant($productVariant);
        $orderItem->snapshotFrom($productVariant); // libellés figés à la vente (A1, 30/10/2026)
        $orderItem->setQuantity($quantity);
        
        // Repli sans prix fourni : même règle que le devis, prix propre de la variante d'abord (11/11/2026, écart relevé au lot VAR ; aucun appelant
        // actuel ne s'en sert : le devis fournit toujours le prix)
        $finalUnitPrice = $unitPrice ?? (float) ($productVariant->getPrice() ?? $productVariant->getProduct()->getEffectivePrice());
        $orderItem->setUnitPrice($finalUnitPrice);
        $orderItem->setTotalPrice($finalUnitPrice * $quantity);

        $saleUnit = $productVariant->getProduct()->getSaleUnit();
        if ($saleUnit) {
            $orderItem->setSaleUnit($saleUnit->getName());
        }

        return $orderItem;
    }
}
