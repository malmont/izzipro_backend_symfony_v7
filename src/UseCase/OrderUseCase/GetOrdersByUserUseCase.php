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

    /**
     * Page de commandes (?page&limit, 01/11/2026) : même forme que la liste complète, plus le total.
     *
     * @return array{items: list<OrderDTO>, total: int}
     */
    public function executePage(int $userId, int $page, int $limit, string $host, string $locale): array
    {
        $result = $this->orderService->getOrdersByUserPage($userId, $page, $limit);

        return ['items' => array_map(fn ($order) => $this->presenter->toDto($order, $host, $locale), $result['items']), 'total' => $result['total']];
    }
}
