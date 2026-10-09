<?php

namespace App\UseCase\OrderUseCase;

use App\Dto\OrderDTO;
use App\Entity\Order;
use App\Entity\User;
use App\Services\OrderService\OrderPresenter;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * GET /api/orders/{id} : détail d'une commande pour son client connecté, ou pour un invité qui présente le jeton reçu
 * à la commande (?token=, order.guest_token). Une commande d'un autre client, ou un jeton faux : 404 (jamais 403, pour
 * ne pas révéler l'existence de la commande).
 */
class GetCustomerOrderUseCase
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly OrderPresenter $presenter)
    {
    }

    /** @throws HttpException 401 sans client ni jeton, 404 */
    public function execute(int $orderId, ?User $user, ?string $token, string $host, string $locale): OrderDTO
    {
        if ($user === null && ($token === null || $token === '')) {
            throw new HttpException(401, 'Connectez-vous, ou présentez le jeton de votre commande (token).');
        }
        $order = $this->emProvider->getEntityManager()->getRepository(Order::class)->find($orderId);
        $owner = $order !== null && $user !== null && $order->getUserId()?->getId() === $user->getId();
        $guest = $order !== null && is_string($token) && $token !== '' && is_string($order->getGuestToken()) && hash_equals($order->getGuestToken(), $token);
        if (!$owner && !$guest) {
            throw new HttpException(404, 'Commande introuvable.');
        }

        return $this->presenter->toDto($order, $host, $locale);
    }
}
