<?php

namespace App\UseCase\OrderUseCase;

use App\Dto\OrderDTO;
use App\Dto\OrderItemDTO;
use App\Services\OrderService\OrderService;
use App\Dto\AdressOutputDTO;

class GetOrdersBySourceUseCase
{
    private $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    public function execute(int $orderSourceId, string $host, ?int $days = null): array
    {
        $orders = $this->orderService->getOrdersByOrderSource($orderSourceId, $days);
        $orderDTOs = [];

        foreach ($orders as $order) {
            $orderItemDTOs = [];
            foreach ($order->getOrderItems() as $orderItem) {
                $orderItemDTOs[] = new OrderItemDTO($orderItem, $host);
            }
            $shippingAdressDTO = $order->getShippingAdress() 
            ? new AdressOutputDTO($order->getShippingAdress()) 
            : null;

            $orderDTOs[] = new OrderDTO(
                $order->getId(),
                $order->getReference(),
                $order->getTotalAmount(),
                $order->getSubTotal(),
                $order->getTotalTax(),
                $order->getShippingCost(),
                $order->getOrderDate()->format('Y-m-d H:i:s'),
                $order->getUserId() ? $order->getUserId()->getId() : null,
                $shippingAdressDTO,
                $order->getOrderSource() ? $order->getOrderSource()->getName() : null,
                $order->getStatus() ? $order->getStatus()->getName() : null,
                $orderItemDTOs
            );
        }

        return $orderDTOs;
    }
}