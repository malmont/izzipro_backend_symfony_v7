<?php

namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Services\OrderService\OrderService;

class GetAccountOrdersUseCase
{
    public function __construct(
        private readonly OrderService $orderService
    ) {}

    /**
     * @return Order[]
     */
    public function execute(int $userId): array
    {
        return $this->orderService->getOrdersByUser($userId);
    }
}
