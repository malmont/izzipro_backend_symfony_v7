<?php

namespace App\UseCase\OrderUseCase;

use App\Dto\OrderDTO;
use App\Dto\OrderItemDTO;
use App\Services\OrderService\OrderService;

class GetOrdersByUserUseCase
{
    private $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    public function execute(int $userId, string $host): array
    {
        $orders = $this->orderService->getOrdersByUser($userId);
        $orderDTOs = [];

        foreach ($orders as $order) {
            $orderItemDTOs = [];

            foreach ($order->getOrderItems() as $orderItem) {
                $orderItemDTOs[] = new OrderItemDTO($orderItem, $host);
            }

            $orderDTOs[] = new OrderDTO(
                $order->getId(),
                $order->getReference(),
                $order->getTotalAmount(),
                $order->getOrderDate()->format('Y-m-d H:i:s'),
                $order->getUserId() ? $order->getUserId()->getId() : null,
                $order->getShippingAdress() ? $order->getShippingAdress()->getId() : null,
                $order->getOrderSource() ? $order->getOrderSource()->getName() : null,
                $orderItemDTOs
            );
        }

        return $orderDTOs;
    }
}
