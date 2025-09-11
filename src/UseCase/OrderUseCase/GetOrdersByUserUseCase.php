<?php

namespace App\UseCase\OrderUseCase;

use App\Dto\OrderDTO;
use App\Dto\OrderItemDTO;
use App\Dto\AdressOutputDTO;
use App\Services\OrderService\OrderService;

class GetOrdersByUserUseCase
{
    private $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    public function execute(int $userId, string $host, string $locale): array
    {
        $orders = $this->orderService->getOrdersByUser($userId);
        $orderDTOs = [];

        foreach ($orders as $order) {
            $orderItemDTOs = [];
            foreach ($order->getOrderItems() as $orderItem) {
                $orderItemDTOs[] = new OrderItemDTO($orderItem, $host);
            }

            $shippingAdressDTO = $order->getShippingAdress() 
                ? new AdressOutputDTO($order->getShippingAdress()) 
                : null;

            $status = $order->getStatus();
            $statusTranslation = $status ? $status->getTranslation($locale) : null;
            $statusName = $statusTranslation ? $statusTranslation->getName() : ($status ? $status->getName() : null);

            $orderDTOs[] = new OrderDTO(
                $order->getId(),
                $order->getReference(),
                $order->getTotalAmount(),
                $order->getOrderDate()->format('Y-m-d H:i:s'),
                $order->getUserId() ? $order->getUserId()->getId() : null,
                $shippingAdressDTO,
                $order->getOrderSource() ? $order->getOrderSource()->getName() : null,
                $statusName, 
                $orderItemDTOs
            );
        }

        return $orderDTOs;
    }
}