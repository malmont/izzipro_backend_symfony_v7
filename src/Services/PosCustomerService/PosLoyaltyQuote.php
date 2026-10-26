<?php

namespace App\Services\PosCustomerService;

use App\Services\CaisseService\CashRegisterException;

/**
 * Rachat de points connu avant l'encaissement (F8) : devis de caisse, devis d'échange, part carte du terminal et crédit de l'échange.
 * Mêmes contrôles, dans le même ordre et avec les mêmes refus que la vente (CreateOrderUseCase), sans aucune écriture :
 * client (CUSTOMERS_DISABLED, CUSTOMER_NOT_FOUND…), puis rachat (LOYALTY_CUSTOMER_REQUIRED, LOYALTY_TICKET_DISCOUNT_CONFLICT,
 * LOYALTY_DISABLED, LOYALTY_CONSENT_REQUIRED, LOYALTY_POINTS_INVALID, LOYALTY_INSUFFICIENT). La récompense devient la remise de
 * ticket « Récompense fidélité (N points) », exactement comme dans la vente.
 */
class PosLoyaltyQuote
{
    public function __construct(private readonly PosCustomerService $customers, private readonly PosLoyaltyService $loyalty)
    {
    }

    /** Points demandés dans un corps de requête (même lecture que la vente) */
    public static function redeemPoints(array $body): int
    {
        return is_array($body['loyalty'] ?? null) && is_numeric($body['loyalty']['redeemPoints'] ?? null) ? (int) $body['loyalty']['redeemPoints'] : 0;
    }

    /**
     * @return array{ticketDiscount: ?array, loyalty: ?array{customerId: int, redeemPoints: int, amount: int, balance: int, balanceAfter: int}}
     *         ticketDiscount : celle du corps, ou la récompense si des points sont rachetés ; loyalty : null sans rachat
     */
    public function apply(?int $customerId, int $redeemPoints, ?array $ticketDiscount): array
    {
        $customer = null;
        if ($customerId !== null) {
            $this->customers->assertEnabled();
            $customer = $this->customers->find($customerId);
        }
        if ($redeemPoints <= 0) {
            return ['ticketDiscount' => $ticketDiscount, 'loyalty' => null];
        }
        if ($customer === null) {
            throw new CashRegisterException('LOYALTY_CUSTOMER_REQUIRED', 'Le rachat de points exige un client rattaché à une vente', 422, [['path' => 'customerId', 'message' => 'requis']]);
        }
        if ($ticketDiscount !== null) {
            throw new CashRegisterException('LOYALTY_TICKET_DISCOUNT_CONFLICT', 'Un rachat de points et une remise de ticket ne se cumulent pas', 409);
        }
        $reward = $this->loyalty->previewReward($customer, $redeemPoints, $this->customers);

        return [
            'ticketDiscount' => ['type' => 'amount', 'value' => $reward['amount'], 'reasonText' => sprintf('Récompense fidélité (%d points)', $redeemPoints)],
            'loyalty' => ['customerId' => (int) $customer->getId(), 'redeemPoints' => $redeemPoints, 'amount' => $reward['amount'], 'balance' => $reward['balance'], 'balanceAfter' => $reward['balance'] - $redeemPoints],
        ];
    }
}
