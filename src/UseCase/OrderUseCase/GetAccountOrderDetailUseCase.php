<?php

namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Services\OrderService\OrderService;

class GetAccountOrderDetailUseCase
{
    public function __construct(
        private readonly OrderService $orderService
    ) {}

    public function execute(int $orderId, int $userId): ?Order
    {
        return $this->orderService->getOrderDetailsForUser($orderId, $userId);
    }
}
