<?php

namespace App\UseCase\PosReturnUseCase;

use App\Dto\CreateOrderMultiPaymentDTO;
use App\Dto\PaymentMethodDTO;
use App\Entity\Order;
use App\Entity\PosReturn;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosAuditService\PosWriteResponseBuilder;
use App\Services\PosReturnService\PosReturnService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\OrderUseCase\CreateOrderUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Retours partiels et échanges (F2, 24/10/2026) : orchestration et transaction SQL. Un échange = retour (avoir) puis
 * nouvelle vente (ticket) dans la même transaction, le retour venant en crédit de la vente.
 */
class PosReturnUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PosReturnService $returns,
        private readonly CreateOrderUseCase $createOrder,
        private readonly PosWriteResponseBuilder $responses,
        private readonly \App\Services\PosDiscountService\PosDiscountService $discounts,
        private readonly \App\Services\CaisseService\CashRoundingService $rounding,
        private readonly \App\Services\PosGiftCardService\PosGiftCardService $giftCards, // cartes-cadeaux et avoirs (F9)
        private readonly \App\Services\PosCustomerService\PosLoyaltyQuote $loyaltyQuote, // rachat de points connu avant l'encaissement (F8)
    ) {
    }

    public function lookup(array $query): array
    {
        return $this->returns->lookup($this->returns->findSale($query['orderId'] ?? null, $query['barcode'] ?? null, $query['receipt'] ?? null, $query['cashRegisterId'] ?? null));
    }

    public function createReturn(User $user, array $body, ?string $posKey): PosReturn
    {
        return $this->transaction(fn () => $this->returns->create($user, $body, $posKey));
    }

    /**
     * {return: {…}, sale: {items, paymentMethods?, cashRegisterId?, …}} : la vente est d'abord chiffrée (devis du serveur) pour
     * fixer le crédit = min(retour, vente) ; le client paie total − crédit par les moyens indiqués, ou reçoit retour − crédit.
     *
     * @return array{return: PosReturn, sale: Order}
     */
    public function createExchange(User $user, array $body, ?string $posKey): array
    {
        $returnInput = is_array($body['return'] ?? null) ? $body['return'] : [];
        $saleInput = is_array($body['sale'] ?? null) ? $body['sale'] : [];
        if (empty($saleInput['items']) || !is_array($saleInput['items'])) {
            throw new CashRegisterException('VALIDATION_FAILED', 'sale.items : au moins un article', 422, [['path' => 'sale.items', 'message' => 'au moins un article attendu']]);
        }
        $cashRegisterId = is_numeric($saleInput['cashRegisterId'] ?? $returnInput['cashRegisterId'] ?? null) ? (int) ($saleInput['cashRegisterId'] ?? $returnInput['cashRegisterId']) : null;
        $returnInput['cashRegisterId'] = $saleInput['cashRegisterId'] = $cashRegisterId;
        $methods = [];
        foreach (is_array($saleInput['paymentMethods'] ?? null) ? $saleInput['paymentMethods'] : [] as $i => $method) {
            if (!is_array($method) || !is_numeric($method['type'] ?? null) || !is_numeric($method['amount'] ?? null) || (float) $method['amount'] < 0) {
                throw new CashRegisterException('PAYMENT_METHODS_INVALID', 'Chaque moyen de paiement doit avoir un type et un montant positif', 400, [['path' => "sale.paymentMethods[$i]", 'message' => '{type, amount}']]);
            }
            $ref = $method['terminalPaymentId'] ?? $method['stripePaymentId'] ?? null;
            $gift = in_array((int) $method['type'], [PaymentMethodDTO::GIFT_CARD, PaymentMethodDTO::STORE_CREDIT], true); // carte-cadeau ou avoir (F9) : code requis
            if ($gift && (!is_string($method['code'] ?? null) || $method['code'] === '')) {
                throw new CashRegisterException('PAYMENT_METHODS_INVALID', 'code : code de la carte-cadeau ou de l\'avoir requis pour les types 4 et 5', 400, [['path' => "sale.paymentMethods[$i].code", 'message' => 'requis']]);
            }
            $methods[] = new PaymentMethodDTO((int) $method['type'], (float) $method['amount'], $ref !== null && is_scalar($ref) ? (string) $ref : null, $gift ? $method['code'] : null);
        }

        return $this->transaction(function () use ($user, $returnInput, $saleInput, $methods, $posKey, $cashRegisterId) {
            // 1. Montants : retour (prix payés) et vente (devis du serveur) → crédit appliqué
            $returnTotal = $this->returns->preview($returnInput);
            $ticketDiscount = is_array($saleInput['ticketDiscount'] ?? null) ? $saleInput['ticketDiscount'] : null;
            // Points rachetés (F8) : crédit calculé sur le total APRÈS récompense ; avant, il l'était sur le total avant récompense et dépassait
            // la vente quand le retour la couvrait (le client perdait la récompense). Sans rachat : inchangé. La vente applique la récompense elle-même.
            $quoteDiscount = \App\Services\PosCustomerService\PosLoyaltyQuote::redeemPoints($saleInput) > 0 ? $this->exchangeLoyalty($returnInput, $saleInput, $ticketDiscount)['ticketDiscount'] : $ticketDiscount;
            $saleTotal = $this->createOrder->quoteTotal($saleInput['items'], $quoteDiscount);
            $credit = min($returnTotal, $saleTotal);
            // 2. Retour : seul le reste (retour − crédit) est remboursé au client
            $return = $this->returns->create($user, $returnInput, $posKey, $credit);
            // 3. Vente : les paiements couvrent vente − crédit (aucun paiement si le crédit couvre tout)
            $paid = (int) round(array_sum(array_map(fn (PaymentMethodDTO $m) => $m->getAmount(), $methods)) * 100);
            $cash = (int) round(array_sum(array_map(fn (PaymentMethodDTO $m) => $m->getType() === 2 ? $m->getAmount() : 0, $methods)) * 100);
            $dto = new CreateOrderMultiPaymentDTO($user->getId(), PosReturnService::ORDER_SOURCE_POS, $methods, null, null, PosReturnService::ORDER_TYPE_SALE, $saleInput['items'], null,
                null, null, null, null, null, null, null, null, null, null, null, null, null, null, null);
            $dto->setCreditApplied($credit);
            $dto->setTicketDiscount($ticketDiscount);
            // Fiche client et fidélité (F8) : client de la vente d'échange (sale.customerId, sinon celui du retour ou de la vente d'origine), points rachetés
            $dto->setCustomerId(is_numeric($saleInput['customerId'] ?? null) ? (int) $saleInput['customerId'] : ($return->getReturnOrder()->getCustomerId() ?? $return->getOrder()?->getCustomerId()));
            $dto->setLoyaltyRedeemPoints(is_array($saleInput['loyalty'] ?? null) && is_numeric($saleInput['loyalty']['redeemPoints'] ?? null) ? (int) $saleInput['loyalty']['redeemPoints'] : 0);
            $dto->setVerifiedPaymentAmount($paid);
            $dto->setPaymentSplit($cash, $paid - $cash);
            $dto->setCashRegisterId($cashRegisterId);
            $sale = $this->createOrder->execute($dto, $user);
            if ($sale instanceof JsonResponse) {
                $body = json_decode($sale->getContent(), true) ?: [];
                throw new CashRegisterException((string) ($body['error'] ?? 'SALE_FAILED'), (string) ($body['message'] ?? $body['error'] ?? 'Vente d\'échange refusée'), $sale->getStatusCode(), $body['errors'] ?? [], array_diff_key($body, array_flip(['error', 'message', 'errors'])));
            }
            $return->setExchangeOrder($sale);
            $this->emProvider->getEntityManager()->flush();

            return ['return' => $return, 'sale' => $sale];
        });
    }

    /**
     * Simulation d'un échange, sans écriture (25/10/2026) : exactement le calcul de createExchange.
     * {return: {orderId, lines}, sale: {items (remises F1), ticketDiscount?, cashRegisterId?}} → montants à encaisser ou à rendre.
     */
    public function quoteExchange(array $body): array
    {
        $returnInput = is_array($body['return'] ?? null) ? $body['return'] : [];
        $saleInput = is_array($body['sale'] ?? null) ? $body['sale'] : [];
        if (empty($saleInput['items']) || !is_array($saleInput['items'])) {
            throw new CashRegisterException('VALIDATION_FAILED', 'sale.items : au moins un article', 422, [['path' => 'sale.items', 'message' => 'au moins un article attendu']]);
        }
        $ticketDiscount = is_array($saleInput['ticketDiscount'] ?? null) ? $saleInput['ticketDiscount'] : null;
        $returnTotal = $this->returns->preview($returnInput);
        // Fiche client et rachat de points de la vente d'échange (F8) : comme l'échange ; clé sale.loyalty seulement si des points sont rachetés
        $loyalty = null;
        if (\App\Services\PosCustomerService\PosLoyaltyQuote::redeemPoints($saleInput) > 0 || is_numeric($saleInput['customerId'] ?? null)) {
            ['ticketDiscount' => $ticketDiscount, 'loyalty' => $loyalty] = $this->exchangeLoyalty($returnInput, $saleInput, $ticketDiscount);
        }
        $quote = $this->discounts->quote(array_values($saleInput['items']), $ticketDiscount);
        $credit = min($returnTotal, (int) $quote['total']);
        $amountDue = (int) $quote['total'] - $credit;
        $refundDue = $returnTotal - $credit;
        // Cartes-cadeaux et avoirs présentés (F9) : part applicable au montant dû, sans débit
        $gift = !empty($saleInput['giftCards']) ? $this->giftCards->preview($saleInput['giftCards'], $amountDue) : null;

        return [
            'returnAmount' => $returnTotal,
            'sale' => ['subtotal' => $quote['subtotal'], 'grossSubtotal' => $quote['grossSubtotal'] ?? $quote['subtotal'], 'discounts' => $quote['discounts'], 'taxes' => $quote['taxes'], 'total' => $quote['total'], 'cashTotal' => $quote['cashTotal']] + ($loyalty !== null ? ['loyalty' => $loyalty] : []),
            'creditApplied' => $credit,
            'amountDue' => $gift ? $gift['amountDue'] : $amountDue,
            'cashAmountDue' => $this->rounding->round($gift ? $gift['amountDue'] : $amountDue),
            'refundDue' => $refundDue,
            'cashRefundDue' => $this->rounding->round($refundDue),
        ] + ($gift ? ['giftCards' => $gift['giftCards'], 'giftCardApplied' => $gift['giftCardApplied'], 'amountDueBeforeGiftCards' => $amountDue] : []);
    }

    /**
     * Rachat de points de la vente d'échange (F8) : client = sale.customerId, sinon celui du retour (return.customerId), sinon celui de la
     * vente d'origine (même règle que createExchange) ; mêmes contrôles que la vente, sans écriture.
     */
    private function exchangeLoyalty(array $returnInput, array $saleInput, ?array $ticketDiscount): array
    {
        $customerId = is_numeric($saleInput['customerId'] ?? null) ? (int) $saleInput['customerId'] : null;
        if ($customerId === null && is_numeric($returnInput['customerId'] ?? null)) {
            $customerId = (int) $returnInput['customerId'];
        }
        if ($customerId === null && is_numeric($returnInput['orderId'] ?? null)) {
            $customerId = $this->emProvider->getEntityManager()->find(Order::class, (int) $returnInput['orderId'])?->getCustomerId();
        }

        return $this->loyaltyQuote->apply($customerId, \App\Services\PosCustomerService\PosLoyaltyQuote::redeemPoints($saleInput), $ticketDiscount);
    }

    public function returnBody(PosReturn $return, string $locale): array
    {
        return $this->returns->toArray($return, $locale);
    }

    public function exchangeBody(PosReturn $return, Order $sale, string $locale): array
    {
        return ['return' => $this->returns->toArray($return, $locale), 'sale' => $this->responses->sale($sale, $locale)];
    }

    /** @template T @param callable(): T $work @return T */
    private function transaction(callable $work): mixed
    {
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $connection->beginTransaction();
        try {
            $result = $work();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }
}
