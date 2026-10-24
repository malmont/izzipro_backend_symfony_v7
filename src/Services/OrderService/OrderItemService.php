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
        
        $finalUnitPrice = $unitPrice ?? (float)$productVariant->getProduct()->getEffectivePrice();
        $orderItem->setUnitPrice($finalUnitPrice);
        $orderItem->setTotalPrice($finalUnitPrice * $quantity);

        $saleUnit = $productVariant->getProduct()->getSaleUnit();
        if ($saleUnit) {
            $orderItem->setSaleUnit($saleUnit->getName());
        }

        return $orderItem;
    }
}
