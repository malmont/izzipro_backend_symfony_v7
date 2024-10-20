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

    public function execute(int $userId, string $host): array
    {
        $orders = $this->orderService->getOrdersByUser($userId);
        $orderDTOs = [];

        foreach ($orders as $order) {
            $orderItemDTOs = [];

            foreach ($order->getOrderItems() as $orderItem) {
                $orderItemDTOs[] = new OrderItemDTO($orderItem, $host);
            }

            // Créer un AdressOutputDTO pour l'adresse de livraison
            $shippingAdressDTO = $order->getShippingAdress() 
                ? new AdressOutputDTO($order->getShippingAdress()) 
                : null;

            $orderDTOs[] = new OrderDTO(
                $order->getId(),
                $order->getReference(),
                $order->getTotalAmount(),
                $order->getOrderDate()->format('Y-m-d H:i:s'),
                $order->getUserId() ? $order->getUserId()->getId() : null,
                $shippingAdressDTO, // Utiliser AdressOutputDTO à la place de l'ID
                $order->getOrderSource() ? $order->getOrderSource()->getName() : null,
                $order->getStatus() ? $order->getStatus()->getName() : null,
                $orderItemDTOs
            );
        }

        return $orderDTOs;
    }
}
