<?php
namespace App\Services\OrderService;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\ProductVariant;

class OrderItemService
{
    public function createOrderItem(Order $order, ProductVariant $productVariant, int $quantity): OrderItems
    {
        $orderItem = new OrderItems();
        $orderItem->setOrderAssociated($order);
        $order->addOrderItem($orderItem);
        $orderItem->setProductVariant($productVariant);
        $orderItem->setQuantity($quantity);
        $orderItem->setUnitPrice($productVariant->getProduct()->getPrice());
        $orderItem->setTotalPrice($orderItem->getUnitPrice() * $quantity);

        return $orderItem;
    }
}
