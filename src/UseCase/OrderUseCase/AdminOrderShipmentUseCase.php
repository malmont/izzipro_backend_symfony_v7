<?php

namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Services\OrderService\OrderShipmentService;
use App\Services\TenantEntityManagerProvider;

/**
 * Expédition et livraison d'une commande depuis le back-office (livraison, lot 1, 07/11/2026) : validation de la saisie du marchand,
 * refus lisibles, puis OrderShipmentService. @return array{status: int, body: array}
 */
class AdminOrderShipmentUseCase
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly OrderShipmentService $shipments)
    {
    }

    public function find(int $orderId): ?Order
    {
        return $this->emProvider->getEntityManager()->getRepository(Order::class)->find($orderId);
    }

    /** @param array<string, mixed> $input carrier, trackingNumber?, trackingUrl?, notify? */
    public function ship(Order $order, array $input, string $domain): array
    {
        if ($blocker = $this->shipments->shipBlocker($order)) {
            return ['status' => 409, 'body' => ['error' => $blocker, 'message' => OrderShipmentService::MESSAGES[$blocker]]];
        }
        $carrier = trim((string) ($input['carrier'] ?? ''));
        $errors = [];
        if ($carrier === '' || mb_strlen($carrier) > 100) {
            $errors[] = ['path' => 'carrier', 'message' => 'nom du transporteur attendu (100 caractères au plus)'];
        }
        $trackingNumber = trim((string) ($input['trackingNumber'] ?? ''));
        if (mb_strlen($trackingNumber) > 100) {
            $errors[] = ['path' => 'trackingNumber', 'message' => '100 caractères au plus'];
        }
        $trackingUrl = trim((string) ($input['trackingUrl'] ?? ''));
        if ($trackingUrl !== '' && (mb_strlen($trackingUrl) > 500 || !preg_match('#^https?://#i', $trackingUrl))) {
            $errors[] = ['path' => 'trackingUrl', 'message' => 'adresse http(s) attendue (500 caractères au plus)'];
        }
        if ($errors !== []) {
            return ['status' => 422, 'body' => ['error' => 'VALIDATION_FAILED', 'message' => 'Saisie incomplète', 'errors' => $errors]];
        }
        $result = $this->shipments->ship($order, $carrier, $trackingNumber !== '' ? $trackingNumber : null, $trackingUrl !== '' ? $trackingUrl : null, (bool) ($input['notify'] ?? true), $domain);

        return ['status' => 200, 'body' => ['shipped' => true] + $result];
    }

    public function deliver(Order $order): array
    {
        if ($blocker = $this->shipments->deliverBlocker($order)) {
            return ['status' => 409, 'body' => ['error' => $blocker, 'message' => OrderShipmentService::MESSAGES[$blocker]]];
        }
        $this->shipments->deliver($order);

        return ['status' => 200, 'body' => ['delivered' => true]];
    }
}
