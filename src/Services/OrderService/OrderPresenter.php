<?php

namespace App\Services\OrderService;

use App\Dto\AdressOutputDTO;
use App\Dto\OrderDTO;
use App\Dto\OrderItemDTO;
use App\Entity\Order;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;

/**
 * Commande telle que le client la lit (GET /api/ordersuser, GET /api/orders/{id}) : montants en cents entiers, devise du
 * site, statut dans la langue demandée, lignes avec leur réservation. Même forme pour la liste et le détail.
 */
final class OrderPresenter
{
    public function __construct(private readonly TenantCurrencyProvider $currency, private readonly ?\App\Services\TenantEntityManagerProvider $emProvider = null)
    {
    }

    public function toDto(Order $order, string $host, string $locale): OrderDTO
    {
        $items = [];
        foreach ($order->getOrderItems() as $orderItem) {
            $items[] = new OrderItemDTO($orderItem, $host);
        }
        $status = $order->getStatus();
        $statusName = $status?->getTranslation($locale)?->getName() ?? $status?->getName();

        $dto = new OrderDTO(
            (int) $order->getId(),
            (string) $order->getReference(),
            (float) $order->getTotalAmount(),
            $order->getSubTotal(),
            $order->getTotalTax(),
            $order->getShippingCost(),
            $order->getOrderDate()->format('Y-m-d H:i:s'),
            $order->getUserId()?->getId(),
            $order->getShippingAdress() ? new AdressOutputDTO($order->getShippingAdress()) : null,
            $order->getOrderSource()?->getName(),
            $statusName,
            $items
        );
        $dto->currency = $this->currency->code();
        $dto->statusId = $status?->getId();
        $dto->carrier = $order->getCarrier() ? ['id' => $order->getCarrier()->getId(), 'name' => $order->getCarrier()->getTranslation($locale)?->getName() ?? $order->getCarrier()->getName()] : null;
        if ($order->getOrderSource()?->getId() === 2 && $this->emProvider) { // vente au comptoir : numéros (C2)
            $dto->pos = OrderDTO::posFields($this->emProvider->getEntityManager()->getRepository(\App\Entity\PosReceipt::class)->forOrders([(int) $order->getId()])[$order->getId()] ?? []);
        }

        return $dto;
    }
}
