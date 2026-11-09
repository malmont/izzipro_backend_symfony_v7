<?php

namespace App\UseCase\PosRentalUseCase;

use App\Entity\Order;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\PosRentalService\PosRentalService;
use App\Services\TenantEntityManagerProvider;

/** Location au comptoir (09/11/2026) : routes /api/pos/rentals. */
class PosRentalUseCase
{
    public function __construct(private readonly PosRentalService $rentals, private readonly CashRegisterResolver $registers, private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return list<array<string, mixed>> */
    public function catalogue(string $productsBaseUrl, string $locale): array
    {
        return $this->rentals->catalogue($productsBaseUrl, $locale);
    }

    /** @return list<array<string, mixed>> */
    public function deposits(?string $status, mixed $cashRegisterId): array
    {
        return $this->rentals->deposits($status, is_numeric($cashRegisterId) ? (int) $cashRegisterId : null);
    }

    /** @return array<string, mixed> */
    public function deposit(int $id): array
    {
        return $this->rentals->toArray($this->rentals->deposit($id));
    }

    /**
     * Caution prise après la vente (remise du matériel) : { orderId, amount, method, cashDetails?, terminalPaymentId?, cashRegisterId? }
     * @return array<string, mixed>
     */
    public function collect(User $user, array $body): array
    {
        $orderId = is_numeric($body['orderId'] ?? null) ? (int) $body['orderId'] : 0;
        $order = $orderId > 0 ? $this->emProvider->getEntityManager()->find(Order::class, $orderId) : null;
        if ($order === null) {
            throw new CashRegisterException('ORDER_NOT_FOUND', 'Vente introuvable', 404, [], ['orderId' => $orderId]);
        }
        if ($order->getOrderSource()?->getId() !== 2 || $order->getOrderType()?->getId() !== 1) {
            throw new CashRegisterException('DEPOSIT_INVALID', 'Une caution s\'encaisse sur une vente au comptoir', 409, [], ['orderId' => $orderId]);
        }
        $items = [];
        foreach ($order->getOrderItems() as $item) {
            if ($item->getBooking() !== null) {
                $items[] = ['productVariantId' => $item->getProductVariant()?->getId(), 'booking' => ['start' => 'x', 'end' => 'x']];
            }
        }
        if ($items === []) {
            throw new CashRegisterException('DEPOSIT_WITHOUT_RENTAL', 'Cette vente ne contient aucune location', 422, [['path' => 'orderId', 'message' => 'aucune ligne de location']]);
        }
        $deposit = $this->rentals->checkDepositInput(['amount' => $body['amount'] ?? null, 'method' => $body['method'] ?? null, 'cashDetails' => $body['cashDetails'] ?? null, 'terminalPaymentId' => $body['terminalPaymentId'] ?? null], $items, 2, 1);
        $caisse = $this->registers->requireOpenSession(is_numeric($body['cashRegisterId'] ?? null) ? (int) $body['cashRegisterId'] : $order->getPosCashRegisterId(), 409);
        $em = $this->emProvider->getEntityManager();
        $em->getConnection()->beginTransaction();
        try {
            $entity = $this->rentals->collect($order, $user, $caisse, $deposit);
            $em->getConnection()->commit();
        } catch (\Throwable $e) {
            if ($em->getConnection()->isTransactionActive()) {
                $em->getConnection()->rollBack();
            }
            throw $e;
        }

        return $this->rentals->toArray($entity);
    }

    /** @return array<string, mixed> */
    public function returnDeposit(int $id, User $user, array $body, ?string $posKey, ?string $approvalToken): array
    {
        return $this->rentals->returnDeposit(
            $this->rentals->deposit($id), $user,
            is_numeric($body['cashRegisterId'] ?? null) ? (int) $body['cashRegisterId'] : null,
            is_numeric($body['retainedAmount'] ?? null) ? (int) round((float) $body['retainedAmount']) : 0,
            isset($body['reason']) ? (string) $body['reason'] : null,
            isset($body['method']) ? (string) $body['method'] : null,
            isset($body['terminalRefundId']) ? (string) $body['terminalRefundId'] : null,
            $posKey, $approvalToken
        );
    }

    /** @return array<string, mixed> */
    public function returnBooking(int $bookingId, User $user, array $body): array
    {
        return $this->rentals->returnBooking($this->rentals->booking($bookingId), $user, is_numeric($body['cashRegisterId'] ?? null) ? (int) $body['cashRegisterId'] : null);
    }

    /** @return list<array<string, mixed>> */
    public function ongoing(mixed $cashRegisterId): array
    {
        return $this->rentals->ongoing(is_numeric($cashRegisterId) ? (int) $cashRegisterId : null);
    }
}
