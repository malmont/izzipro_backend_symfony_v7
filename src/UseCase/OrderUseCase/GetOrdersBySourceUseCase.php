<?php

namespace App\UseCase\OrderUseCase;

use App\Dto\OrderDTO;
use App\Dto\OrderItemDTO;
use App\Services\OrderService\OrderService;

class GetOrdersBySourceUseCase
{
    private $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    public function execute(int $orderSourceId, string $host, ?int $days = null): array
    {
        // Pass the number of days to the service
        $orders = $this->orderService->getOrdersByOrderSource($orderSourceId, $days);
        $orderDTOs = [];

        foreach ($orders as $order) {
            $orderItemDTOs = [];

            // Transform OrderItems to DTO
            foreach ($order->getOrderItems() as $orderItem) {
                $orderItemDTOs[] = new OrderItemDTO($orderItem, $host);
            }

            // Transform Order to DTO
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
