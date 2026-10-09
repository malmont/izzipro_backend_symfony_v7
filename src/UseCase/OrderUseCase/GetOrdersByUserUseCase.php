<?php

namespace App\UseCase\OrderUseCase;

use App\Dto\OrderDTO;
use App\Services\OrderService\OrderPresenter;
use App\Services\OrderService\OrderService;

/** GET /api/ordersuser : commandes du client connecté, de la plus récente à la plus ancienne, montants en cents */
class GetOrdersByUserUseCase
{
    public function __construct(private readonly OrderService $orderService, private readonly OrderPresenter $presenter)
    {
    }

    /** @return list<OrderDTO> */
    public function execute(int $userId, string $host, string $locale): array
    {
        return array_map(fn ($order) => $this->presenter->toDto($order, $host, $locale), $this->orderService->getOrdersByUser($userId));
    }
}
