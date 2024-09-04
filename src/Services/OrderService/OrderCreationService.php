<?php
namespace App\Services\OrderService;

use App\Entity\Order;
use App\Entity\User;
use App\Entity\OrderSource;
use App\Entity\Carrier;
use App\Entity\StatusCommande;
use App\Entity\OrderType;
use App\Entity\Adress;

class OrderCreationService
{
    public function createOrder(
        User $user,
        OrderSource $orderSource,
        Adress $address,
        Carrier $carrier,
        StatusCommande $statusCommande,
        OrderType $orderType
    ): Order {
        $order = new Order();
        $order->setReference('REF#' . uniqid());
        $order->setUserId($user);
        $order->setOrderType($orderType);
        $order->setOrderSource($orderSource);
        $order->setOrderDate(new \DateTime());
        $order->setShippingAdress($address);
        $order->setCarrier($carrier);
        $order->setStatus($statusCommande);

        return $order;
    }
}
