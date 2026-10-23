<?php

namespace App\Services\PosReturnService;

use App\Dto\CartQuoteInputDto;
use App\Entity\Caisse;
use App\Entity\CashRegister;
use App\Entity\Entreprise;
use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderSource;
use App\Entity\OrderTax;
use App\Entity\OrderType;
use App\Entity\Payments;
use App\Entity\PosReceipt;
use App\Entity\PosReturn;
use App\Entity\PosTerminalPayment;
use App\Entity\ProductVariant;
use App\Entity\StatusCommande;
use App\Entity\Tax;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\CaisseService\CashRoundingService;
use App\Services\OrderService\CartQuoteCalculator;
use App\Services\OrderService\CartQuoteException;
use App\Services\OrderService\OrderCreationService;
use App\Services\OrderService\OrderItemService;
use App\Services\OrderService\OrderTaxAddressResolver;
use App\Services\PosAuditService\PosApprovalService;
use App\Services\PosAuditService\PosAuditService;
use App\Services\PosAuditService\PosReceiptService;
use App\Services\PosTerminalService\PosTerminalPaymentService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;
use App\UseCase\OrderUseCase\PaymentHandlerUseCase;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;

/**
 * Retours partiels et échanges en caisse (F2, 24/10/2026). Vente d'origine retrouvée par son ticket (référence ou
 * code-barres IZ-T-<poste>-<numéro>) ; lignes remboursables = quantité vendue moins déjà retournée ; montant = prix payé
 * de la ligne + taxes au prorata de la vente ; remboursement par le moyen d'origine (carte d'abord, puis espèces) ou
 * espèces, selon les réglages du site ; commande de retour (RetourClient) liée à la vente, avoir numéroté (C2), journal,
 * stock, rapports. Tout s'exécute dans la transaction SQL ouverte par le cas d'usage.
 */
class PosReturnService
{
    public const ORDER_SOURCE_POS = 2;
    public const ORDER_TYPE_SALE = 1;
    public const ORDER_TYPE_RETURN = 2;
    public const STATUS_COMPLETED = 3;
    public const STATUS_CANCELED = 7;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CashRegisterResolver $registers,
        private readonly CashRoundingService $rounding,
        private readonly CartQuoteCalculator $quotes,
        private readonly OrderTaxAddressResolver $taxAddress,
        private readonly OrderCreationService $orders,
        private readonly OrderItemService $orderItems,
        private readonly PaymentHandlerUseCase $payments,
        private readonly UpdateStockAndInventoryUseCase $stock,
        private readonly HandleCaisseTransactionUseCase $caisseTransactions,
        private readonly PosReceiptService $receipts,
        private readonly PosAuditService $audit,
        private readonly PosApprovalService $approvals,
        private readonly PosTerminalPaymentService $terminalPayments,
        private readonly \App\Services\PosGiftCardService\PosGiftCardService $giftCards, // cartes-cadeaux et avoirs (F9, 27/10/2026)
        private readonly \App\Services\PosCustomerService\PosCustomerService $customers, // fiche client (F8, 28/10/2026)
        private readonly \App\Services\PosCustomerService\PosLoyaltyService $loyalty, // fidélité (F8)
    ) {
    }

    // ---------------------------------------------------------------- code-barres et recherche

    /** Valeur du code-barres (Code 128) imprimé sur un ticket ou un avoir : IZ-T-<poste>-<numéro>, IZ-A-<poste>-<numéro> */
    public static function barcode(PosReceipt $receipt): string
    {
        return sprintf('IZ-%s-%d-%d', match ($receipt->getKind()) { PosReceipt::CREDIT_NOTE => 'A', PosReceipt::GIFT_CARD => 'C', default => 'T' }, $receipt->getCashRegister()->getId(), $receipt->getNumber());
    }

    /** Vente d'origine par ?orderId=, ?barcode=IZ-T-1-123 ou ?receipt=C1-123 (référence imprimée, préfixe du poste) */
    public function findSale(mixed $orderId, mixed $barcode, mixed $receipt, mixed $cashRegisterId): Order
    {
        $em = $this->emProvider->getEntityManager();
        if (is_numeric($orderId)) {
            return $em->find(Order::class, (int) $orderId) ?? throw new CashRegisterException('ORDER_NOT_FOUND', 'Vente introuvable', 404, [], ['orderId' => (int) $orderId]);
        }
        if (is_string($barcode) && $barcode !== '') {
            if (!preg_match('/^IZ-([TA])-(\d+)-(\d+)$/i', trim($barcode), $m)) {
                throw new CashRegisterException('BARCODE_INVALID', 'Code-barres inconnu : IZ-T-<poste>-<numéro> attendu', 422, [], ['barcode' => $barcode]);
            }
            $kind = strtoupper($m[1]) === 'A' ? PosReceipt::CREDIT_NOTE : PosReceipt::TICKET;
            $found = $em->getRepository(PosReceipt::class)->findOneBy(['cashRegister' => (int) $m[2], 'kind' => $kind, 'number' => (int) $m[3]]);
            if (!$found) {
                throw new CashRegisterException('RECEIPT_NOT_FOUND', 'Aucun ticket pour ce code-barres', 404, [], ['barcode' => $barcode]);
            }

            return $kind === PosReceipt::CREDIT_NOTE && $found->getOrder()->getReturnedFrom() ? $found->getOrder()->getReturnedFrom() : $found->getOrder();
        }
        if (is_string($receipt) && trim($receipt) !== '') {
            $reference = trim($receipt);
            $registers = $em->getRepository(CashRegister::class)->findAll();
            $candidates = [];
            foreach ($registers as $register) {
                $prefix = (string) $register->getReceiptPrefix();
                if ($prefix !== '' && str_starts_with(strtoupper($reference), strtoupper($prefix)) && ctype_digit(substr($reference, strlen($prefix)))) {
                    $candidates[] = [$register, (int) substr($reference, strlen($prefix))];
                }
            }
            if (!$candidates && ctype_digit($reference)) {
                $scope = is_numeric($cashRegisterId) ? [$this->registers->resolve((int) $cashRegisterId)] : array_filter($registers, fn (CashRegister $r) => $r->isActive());
                foreach ($scope as $register) {
                    $candidates[] = [$register, (int) $reference];
                }
            }
            $matches = [];
            $registersFound = [];
            foreach ($candidates as [$register, $number]) {
                $found = $em->getRepository(PosReceipt::class)->findOneBy(['cashRegister' => $register, 'kind' => PosReceipt::TICKET, 'number' => $number]);
                if ($found) {
                    $matches[$found->getOrder()->getId()] = $found->getOrder();
                    $registersFound[] = $register->getId();
                }
            }
            if (count($matches) > 1) {
                throw new CashRegisterException('RECEIPT_AMBIGUOUS', 'Plusieurs postes ont ce numéro : précisez cashRegisterId ou scannez le code-barres', 409, [], ['receipt' => $reference, 'cashRegisterIds' => $registersFound]);
            }
            if ($matches) {
                return array_values($matches)[0];
            }
            throw new CashRegisterException('RECEIPT_NOT_FOUND', 'Aucune vente pour ce numéro de ticket', 404, [], ['receipt' => $reference]);
        }
        throw new CashRegisterException('VALIDATION_FAILED', 'orderId, barcode ou receipt requis', 422, [['path' => 'receipt', 'message' => 'orderId, barcode ou receipt requis']]);
    }

    /** Vente et lignes remboursables : {order, receipt, payments, lines, eligible} */
    public function lookup(Order $order): array
    {
        $em = $this->emProvider->getEntityManager();
        $settings = $this->settings();
        $eligible = ['allowed' => true, 'reason' => null];
        if ($order->getOrderSource()?->getId() !== self::ORDER_SOURCE_POS || $order->getOrderType()?->getId() !== self::ORDER_TYPE_SALE) {
            $eligible = ['allowed' => false, 'reason' => 'ORDER_NOT_RETURNABLE'];
        } elseif ($order->getStatus()?->getId() === self::STATUS_CANCELED) {
            $eligible = ['allowed' => false, 'reason' => 'ORDER_CANCELED'];
        } elseif ($settings['maxDays'] !== null && $order->getOrderDate() < new \DateTimeImmutable('-' . $settings['maxDays'] . ' days')) {
            $eligible = ['allowed' => false, 'reason' => 'RETURN_DEADLINE_EXCEEDED'];
        }
        $lines = $this->refundableLines($order);
        if ($eligible['allowed'] && array_sum(array_column($lines, 'returnableQuantity')) === 0) {
            $eligible = ['allowed' => false, 'reason' => 'NOTHING_LEFT_TO_RETURN'];
        }
        $receipt = $this->receipts->forOrder($order)[PosReceipt::TICKET] ?? null;
        $refunded = $em->getRepository(PosReturn::class)->refundedAmounts($order);
        $terminal = $this->terminalPayments->findByOrder((int) $order->getId());
        $paid = ['cash' => 0, 'card' => 0, 'other' => 0];
        foreach ($order->getPayments() as $payment) {
            $amount = (int) round((float) $payment->getAmount());
            if ($amount <= 0) {
                continue;
            }
            $paid[match ($payment->getPaymentMethod()?->getId()) { 2 => 'cash', 1 => 'card', default => 'other' }] += $amount;
        }

        return [
            'orderId' => $order->getId(), 'reference' => $order->getReference(), 'soldAt' => $order->getOrderDate()?->format(\DateTimeInterface::ATOM),
            'receiptNumber' => $receipt?->getNumber(), 'receiptReference' => $receipt ? $receipt->getCashRegister()->receiptReference($receipt->getNumber()) : null,
            'barcode' => $receipt ? self::barcode($receipt) : null,
            'cashRegister' => $receipt ? ['id' => $receipt->getCashRegister()->getId(), 'name' => $receipt->getCashRegister()->getName()] : null,
            'totalAmount' => (int) round((float) $order->getTotalAmount()), 'cashRounding' => $order->getCashRounding(),
            'payments' => ['cash' => $paid['cash'], 'card' => $paid['card'], 'other' => $paid['other'],
                // Cartes-cadeaux et avoirs (F9) : part réglée par carte ou avoir et part encore recréditable
                'giftCard' => $order->getGiftCardApplied(), 'giftCardRefundable' => (int) array_sum($em->getRepository(\App\Entity\PosGiftCardTransaction::class)->redeemedAvailable((int) $order->getId())),
                'terminal' => $terminal ? ['paymentId' => $terminal->getId(), 'paymentMethodType' => $terminal->getPaymentMethodType(), 'cardBrand' => $terminal->getCardBrand(), 'last4' => $terminal->getLast4(), 'refundable' => $terminal->getRefundable()] : null],
            'refunded' => $refunded,
            'lines' => $lines,
            'eligible' => $eligible,
            'returnDeadline' => $settings['maxDays'] !== null && $order->getOrderDate() ? \DateTimeImmutable::createFromInterface($order->getOrderDate())->modify('+' . $settings['maxDays'] . ' days')->format(\DateTimeInterface::ATOM) : null,
            'reasons' => $settings['reasons'], 'restockDefault' => $settings['restockDefault'],
        ];
    }

    /** @return list<array> lignes vendues avec quantités retournées et montants remboursables (cents) */
    public function refundableLines(Order $order): array
    {
        $returned = $this->emProvider->getEntityManager()->getRepository(PosReturn::class)->returnedQuantities($order);
        $itemsTotal = $this->itemsTotal($order);
        $lines = [];
        foreach ($order->getOrderItems() as $item) {
            $qty = (int) $item->getQuantity();
            $before = $returned[$item->getId()] ?? 0;
            // Net payé de la prochaine unité (F1 : total_price est le net des remises ; cumul exact comme pour les taxes)
            $unit = self::taxForQuantity((int) round((float) $item->getTotalPrice()), $qty, $before, 1);
            // Taxes de la prochaine unité retournée (méthode cumulative : la somme des retours égale la part de la ligne)
            $taxes = array_map(fn ($t) => ['label' => $t['label'], 'tax' => $t['tax'], 'amount' => self::taxForQuantity($t['amount'], $qty, $before, 1)], $this->lineTaxShare($order, $item, $itemsTotal));
            $variant = $item->getProductVariant();
            $lines[] = [
                'orderItemId' => $item->getId(), 'productVariantId' => $variant?->getId(), 'name' => $item->displayName(),
                'custom' => $item->isCustom(), 'catalogUnitPrice' => $item->getCatalogUnitPrice(), // article libre et prix manuel (F7) : retour au prix payé
                'saleUnit' => $item->getSaleUnit(), 'quantity' => $qty, 'returnedQuantity' => $returned[$item->getId()] ?? 0,
                'returnableQuantity' => max(0, $qty - ($returned[$item->getId()] ?? 0)),
                // unitPrice = NET payé de la prochaine unité (remises déduites, F1) ; unitGross = prix unitaire brut ; discountAmount = remises de la ligne
                'unitPrice' => $unit, 'unitGross' => (int) round((float) $item->getUnitPrice()), 'discountAmount' => $item->getDiscountAmount(),
                'unitTaxes' => array_sum(array_column($taxes, 'amount')), 'unitRefundable' => $unit + array_sum(array_column($taxes, 'amount')),
                'taxes' => array_map(fn ($t) => ['label' => $t['label'], 'rate' => $t['tax'] ? (float) $t['tax']->getRate() : null, 'amount' => $t['amount']], $taxes), // jamais l'entité (sérialisation)
            ];
        }

        return $lines;
    }

    // ---------------------------------------------------------------- retour

    /**
     * Crée le retour dans la transaction SQL ouverte par l'appelant.
     *
     * @param array $input {orderId?, items? (sans ticket : [{productVariantId, quantity}]), lines: [{orderItemId, quantity}], reasonCode?, reasonText?,
     *                     restock?, refund: {method, terminalRefundId?}, cashRegisterId?}
     * @param int $creditApplied part du retour appliquée à une vente d'échange (cents) : seul le reste est remboursé
     */
    public function create(User $user, array $input, ?string $posKey, int $creditApplied = 0): PosReturn
    {
        $em = $this->emProvider->getEntityManager();
        $settings = $this->settings();
        $caisse = $this->registers->requireOpenSession(is_numeric($input['cashRegisterId'] ?? null) ? (int) $input['cashRegisterId'] : null, 409);
        $register = $caisse->getCashRegister();
        $this->receipts->lockRegister($register);

        $original = is_numeric($input['orderId'] ?? null) ? $this->findSale((int) $input['orderId'], null, null, null) : null;
        if ($original === null && !$settings['withoutReceipt']) {
            throw new CashRegisterException('RETURN_WITHOUT_RECEIPT_FORBIDDEN', 'Retour sans ticket refusé sur ce site : retrouvez la vente d\'origine', 409);
        }
        $reason = $this->reason($input, $settings);
        $restock = array_key_exists('restock', $input) ? (bool) $input['restock'] : $settings['restockDefault'];
        $method = (string) ($input['refund']['method'] ?? PosReturn::METHOD_ORIGINAL);
        if (!in_array($method, PosReturn::METHODS, true)) {
            throw new CashRegisterException('REFUND_METHOD_INVALID', 'refund.method : original, cash, card, declared_card ou store_credit attendu', 422, [['path' => 'refund.method', 'message' => 'original, cash, card, declared_card ou store_credit']]);
        }

        // Lignes et montants
        [$lines, $subtotal, $taxes] = $original ? $this->linesFromSale($original, $input['lines'] ?? null, $settings) : $this->linesFromCatalog($input['items'] ?? null);
        $taxTotal = (int) array_sum(array_map(fn ($t) => $t['amount'], $taxes));
        $total = $subtotal + $taxTotal;
        if ($total <= 0) {
            throw new CashRegisterException('RETURN_EMPTY', 'Aucun article à retourner', 422);
        }
        $creditApplied = min(max(0, $creditApplied), $total);
        $toRefund = $total - $creditApplied;

        // Validation d'un responsable (C3) : action return, contexte {orderId, amount}
        $this->approvals->requireFor(PosApprovalService::RETURN, $user, ['orderId' => $original?->getId() ?? 0, 'amount' => $total], $total);

        // Cartes-cadeaux et avoirs (F9) : part de la vente réglée par carte ou avoir encore recréditable ; « original » la recrédite
        // d'abord, puis carte bancaire, puis espèces ; espèces interdites pour cette part sauf réglage ; « store_credit » = avoir
        $giftSettings = $this->giftCards->settings();
        $giftAvailable = $original ? $em->getRepository(\App\Entity\PosGiftCardTransaction::class)->redeemedAvailable((int) $original->getId()) : [];
        $giftPart = 0;
        $storeCreditAmount = 0;
        if ($method === PosReturn::METHOD_STORE_CREDIT) {
            if (!$giftSettings['storeCredits']['enabled']) {
                throw new CashRegisterException('STORE_CREDIT_DISABLED', 'Les avoirs sont désactivés sur ce site', 409);
            }
            $storeCreditAmount = $toRefund;
            [$cardRefund, $cashExact, $terminal] = [0, 0, null];
        } else {
            if ($method === PosReturn::METHOD_ORIGINAL) {
                $giftPart = min($toRefund, (int) array_sum($giftAvailable));
            } elseif ($method === PosReturn::METHOD_CASH && array_sum($giftAvailable) > 0 && !$giftSettings['cashRefundBeyond']) {
                throw new CashRegisterException('CASH_REFUND_FORBIDDEN', 'Remboursement en espèces de la part réglée par carte-cadeau ou avoir refusé sur ce site (recrédit sur la carte)', 409, [], ['giftCardPaid' => (int) array_sum($giftAvailable)]);
            }
            // Répartition du reste : carte d'abord (jusqu'au payé par carte non remboursé), puis espèces
            [$cardRefund, $cashExact, $terminal] = $this->split($original, $method, $toRefund - $giftPart, $settings);
        }
        $cashRefund = $cashExact > 0 ? $this->rounding->round($cashExact) : 0;
        $cashRounding = $cashRefund - $cashExact;
        $terminalRefundId = null;
        if ($cardRefund > 0 && $terminal !== null) {
            $terminalRefundId = $this->terminalPayments->refundForReturn($terminal, $cardRefund, is_string($input['refund']['terminalRefundId'] ?? null) ? $input['refund']['terminalRefundId'] : null, $user, $posKey);
            if ($em->getRepository(PosReturn::class)->findByTerminalRefund($terminalRefundId)) {
                throw new CashRegisterException('TERMINAL_REFUND_ALREADY_USED', 'Ce remboursement sur le terminal est déjà rattaché à un retour', 409, [], ['terminalRefundId' => $terminalRefundId]);
            }
        }

        // Commande de retour liée à la vente, lignes aux prix payés, taxes au prorata, stock
        $returnOrder = $this->orders->createOrder($user, $em->getReference(OrderSource::class, self::ORDER_SOURCE_POS), null, null,
            $em->getReference(StatusCommande::class, self::STATUS_COMPLETED), $em->getReference(OrderType::class, self::ORDER_TYPE_RETURN));
        $returnOrder->setReturnedFrom($original);
        // Fiche client (F8) : client du corps (retour sans ticket) ou de la vente d'origine
        $customer = is_numeric($input['customerId'] ?? null) ? $this->customers->find((int) $input['customerId']) : null;
        $customerId = $customer?->getId() ?? $original?->getCustomerId();
        $returnOrder->setCustomerId($customerId);
        foreach ($lines as $line) {
            if ($line['variant'] === null) { // article libre (F7) : ligne sans variante, au prix payé, aucun stock
                $item = (new OrderItems())->setOrderAssociated($returnOrder)->setQuantity($line['quantity'])->setUnitPrice((float) $line['unitPrice'])->setTotalPrice((float) ($line['net'] ?? $line['unitPrice'] * $line['quantity']))
                    ->setCustomLabel($line['item']?->getCustomLabel())->setTaxable($line['item']?->isTaxable() ?? true);
                $returnOrder->addOrderItem($item);
            } else {
                $item = $this->orderItems->createOrderItem($returnOrder, $line['variant'], $line['quantity'], (float) $line['unitPrice']);
            }
            $item->setReturnedFromItem($line['item']);
            if (isset($line['net'])) {
                $item->setTotalPrice((float) $line['net']); // net exact (cumul), le prix unitaire étant le net moyen
            }
            $em->persist($item);
            if ($restock && $line['variant'] !== null) {
                $this->stock->execute($line['variant'], $line['quantity'], true);
            }
        }
        foreach ($taxes as $tax) {
            if ($tax['tax'] !== null) {
                $orderTax = (new OrderTax())->setOrderTax($returnOrder)->setTax($tax['tax'])->setAmount(-$tax['amount']);
                $returnOrder->addOrderTax($orderTax);
                $em->persist($orderTax);
            }
        }
        $returnOrder->setSubTotal(-$subtotal)->setTotalTax(-$taxTotal)->setTotalAmount(-$total)->setCashRounding(-$cashRounding);
        $returnOrder->setCreditApplied($creditApplied);
        $em->persist($returnOrder);
        $em->flush();
        // Cartes-cadeaux (F9) : recrédit des cartes de la vente d'origine, ou avoir pour tout le remboursement
        $giftRefund = $giftPart > 0 ? $this->giftCards->refundToCards($giftAvailable, $giftPart, $returnOrder, (int) $original->getId(), $caisse, $user) : ['amount' => 0, 'cards' => []];
        $storeCredit = $storeCreditAmount > 0 ? $this->giftCards->issueStoreCredit($storeCreditAmount, $returnOrder, $caisse, $user) : null;
        if ($cashRefund > 0) {
            $this->payments->handlePayment($returnOrder, -$cashRefund, 2, 1, 2, new \DateTime(), null);
        }
        if ($cardRefund > 0) {
            $this->payments->handlePayment($returnOrder, -$cardRefund, 1, 1, 2, new \DateTime(), null);
            if ($terminal !== null) {
                foreach ($em->getRepository(Payments::class)->findBy(['orderPayment' => $returnOrder, 'paymentMethod' => 1]) as $payment) {
                    $payment->setStripePaymentId($terminal->getStripePaymentIntentId())->setStripeStatus('refunded')->setStripeCardBrand($terminal->getCardBrand())->setStripeLast4($terminal->getLast4());
                }
            }
        }
        // Transaction de caisse REFUND (rapports X et Z), avoir numéroté dans la suite du poste (C2)
        $this->caisseTransactions->execute($caisse, $returnOrder, $user, $toRefund, HandleCaisseTransactionUseCase::REFUND, [], $cashRefund, $cashRounding !== 0 ? -$cashRounding : null);
        $creditNote = $this->receipts->issue($returnOrder, $caisse, PosReceipt::CREDIT_NOTE, -$total);

        $return = (new PosReturn())->setOrder($original)->setReturnOrder($returnOrder)->setCashRegister($register)->setCaisseId($caisse->getId())->setUserId((int) $user->getId())
            ->setReasonCode($reason['code'])->setReasonText($reason['text'])->setRestock($restock)->setRefundMethod($method)
            ->setAmount($total)->setCashRefund($cashRefund)->setCardRefund($cardRefund)->setCashRounding($cashRounding)->setCreditApplied($creditApplied)
            ->setTerminalPaymentId($terminal?->getId())->setTerminalRefundId($terminalRefundId)
            ->setGiftCardRefund($giftRefund['amount'])->setStoreCreditId($storeCredit !== null ? $storeCredit['card']->getId() : null);
        $em->persist($return);
        $em->flush();
        if ($storeCredit !== null) {
            $this->giftCards->attachReturn($storeCredit['card'], (int) $return->getId());
            if ($customerId !== null) {
                $storeCredit['card']->setCustomerId($customerId);
            }
        }
        // Fidélité (F8) : reprise des points de la vente d'origine au prorata du net remboursé
        $loyaltyReturned = $original !== null ? $this->loyalty->onReturn($original, $subtotal, (int) $returnOrder->getId(), $caisse, $user) : ['points' => 0, 'shortfall' => 0];
        $em->flush();

        $this->audit->record(PosAuditService::REFUND, [
            'kind' => 'return', 'returnId' => $return->getId(), 'reference' => $returnOrder->getReference(), 'originalOrderId' => $original?->getId(),
            'creditNoteNumber' => $creditNote->getNumber(), 'creditNoteReference' => $register->receiptReference($creditNote->getNumber()),
            'lines' => array_map(fn ($l) => ['orderItemId' => $l['item']?->getId(), 'productVariantId' => $l['variant']?->getId(), 'quantity' => $l['quantity'], 'unitPrice' => $l['unitPrice']], $lines),
            'reason' => $reason, 'restock' => $restock, 'refundMethod' => $method,
            'amount' => $total, 'creditApplied' => $creditApplied, 'cashRefund' => $cashRefund, 'cardRefund' => $cardRefund, 'cashRounding' => $cashRounding,
            'giftCardRefund' => $giftRefund, 'storeCredit' => $storeCredit ? ['giftCardId' => $storeCredit['card']->getId(), 'last4' => $storeCredit['card']->getLast4(), 'amount' => $storeCreditAmount] : null,
            'customerId' => $customerId, 'loyaltyReturned' => $loyaltyReturned,
            'terminal' => $terminal ? ['paymentId' => $terminal->getId(), 'refundId' => $terminalRefundId, 'paymentMethodType' => $terminal->getPaymentMethodType()] : null,
            'subTotal' => $subtotal, 'totalTax' => $taxTotal, 'taxes' => array_map(fn ($t) => ['label' => $t['label'], 'amount' => $t['amount']], $taxes),
        ], $caisse, null, 'order', $returnOrder->getId(), $user);

        return $return;
    }

    /** Représentation d'un retour (réponse 201, rejeu, échange) */
    public function toArray(PosReturn $return, string $locale = 'fr'): array
    {
        $returnOrder = $return->getReturnOrder();
        $creditNote = $this->receipts->forOrder($returnOrder)[PosReceipt::CREDIT_NOTE] ?? null;
        $terminal = $return->getTerminalPaymentId() ? $this->emProvider->getEntityManager()->find(PosTerminalPayment::class, $return->getTerminalPaymentId()) : null;
        $lines = [];
        foreach ($returnOrder->getOrderItems() as $item) {
            $lines[] = ['orderItemId' => $item->getReturnedFromItem()?->getId(), 'productVariantId' => $item->getProductVariant()?->getId(), 'name' => $item->displayName(),
                'quantity' => $item->getQuantity(), 'unitPrice' => (int) round((float) $item->getUnitPrice()), 'totalPrice' => (int) round((float) $item->getTotalPrice())];
        }

        return [
            'returnId' => $return->getId(), 'returnOrderId' => $returnOrder->getId(), 'orderId' => $return->getOrder()?->getId(), 'exchangeOrderId' => $return->getExchangeOrder()?->getId(),
            'creditNoteNumber' => $creditNote?->getNumber(), 'creditNoteReference' => $creditNote ? $return->getCashRegister()->receiptReference($creditNote->getNumber()) : null,
            'barcode' => $creditNote ? self::barcode($creditNote) : null,
            'cashRegister' => ['id' => $return->getCashRegister()->getId(), 'name' => $return->getCashRegister()->getName()],
            'amount' => $return->getAmount(), 'subTotal' => -(int) round((float) $returnOrder->getSubTotal()), 'totalTax' => -(int) round((float) $returnOrder->getTotalTax()),
            'creditApplied' => $return->getCreditApplied(),
            'refund' => ['method' => $return->getRefundMethod(), 'amount' => $return->getAmount() - $return->getCreditApplied(), 'cash' => $return->getCashRefund(), 'card' => $return->getCardRefund(), 'cashRounding' => $return->getCashRounding(),
                'terminal' => $terminal ? ['paymentId' => $terminal->getId(), 'refundId' => $return->getTerminalRefundId(), 'paymentMethodType' => $terminal->getPaymentMethodType(), 'cardBrand' => $terminal->getCardBrand(), 'last4' => $terminal->getLast4()] : null,
                // Cartes-cadeaux et avoirs (F9) : part recréditée sur les cartes de la vente, avoir émis (code en clair une seule fois, à la création)
                'giftCard' => $return->getGiftCardRefund(),
                'storeCredit' => ($credit = $return->getStoreCreditId() ? $this->emProvider->getEntityManager()->find(\App\Entity\PosGiftCard::class, $return->getStoreCreditId()) : null) ? $this->giftCards->toArray($credit, $this->giftCards->issuedCode($credit->getId())) : null],
            'reason' => ['code' => $return->getReasonCode(), 'text' => $return->getReasonText()], 'restock' => $return->isRestock(),
            'customerId' => $returnOrder->getCustomerId(), // fiche client (F8)
            'loyalty' => $this->loyalty->returnSummary((int) $returnOrder->getId()), // points repris {returned, shortfall (déjà dépensés), balance} ou null
            'lines' => $lines,
            'taxes' => array_values(array_map(fn (OrderTax $t) => ['label' => $t->getTax()?->getName(), 'amount' => -(int) round((float) $t->getAmount())], $returnOrder->getOrderTaxes()->toArray())),
            'createdAt' => $return->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'store' => \App\Dto\StoreInfoDto::fromEntreprise($this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([])),
        ];
    }

    /** Total d'un retour avant toute écriture (pour décider du crédit d'un échange) */
    public function preview(array $input): int
    {
        $settings = $this->settings();
        $original = is_numeric($input['orderId'] ?? null) ? $this->findSale((int) $input['orderId'], null, null, null) : null;
        [, $subtotal, $taxes] = $original ? $this->linesFromSale($original, $input['lines'] ?? null, $settings) : $this->linesFromCatalog($input['items'] ?? null);

        return $subtotal + (int) array_sum(array_map(fn ($t) => $t['amount'], $taxes));
    }

    public function settings(): array
    {
        $e = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);

        return [
            'maxDays' => $e?->getPosReturnMaxDays(), 'withoutReceipt' => $e?->isPosReturnWithoutReceipt() ?? false, 'restockDefault' => $e?->isPosReturnRestockDefault() ?? true,
            'cashRefundForCardSale' => $e?->isPosReturnCashForCardSale() ?? false, 'reasons' => $e ? $e->returnReasons() : array_map(fn ($l) => ['code' => strtolower($l), 'label' => $l], Entreprise::RETURN_REASONS_DEFAULT),
        ];
    }

    // ---------------------------------------------------------------- interne

    /** @return array{0: list<array>, 1: int, 2: list<array{label: string, amount: int, tax: ?Tax}>} lignes, sous-total, taxes */
    private function linesFromSale(Order $original, mixed $requested, array $settings): array
    {
        if ($original->getOrderSource()?->getId() !== self::ORDER_SOURCE_POS || $original->getOrderType()?->getId() !== self::ORDER_TYPE_SALE) {
            throw new CashRegisterException('ORDER_NOT_RETURNABLE', 'Seule une vente en caisse peut faire l\'objet d\'un retour', 409, [], ['orderId' => $original->getId()]);
        }
        if ($original->getStatus()?->getId() === self::STATUS_CANCELED) {
            throw new CashRegisterException('ORDER_CANCELED', 'Cette vente a été annulée et remboursée en entier', 409, [], ['orderId' => $original->getId()]);
        }
        if ($settings['maxDays'] !== null && $original->getOrderDate() < new \DateTimeImmutable('-' . $settings['maxDays'] . ' days')) {
            throw new CashRegisterException('RETURN_DEADLINE_EXCEEDED', sprintf('Délai de retour dépassé (%d jours)', $settings['maxDays']), 409, [], ['days' => $settings['maxDays'], 'soldAt' => $original->getOrderDate()?->format(\DateTimeInterface::ATOM)]);
        }
        if (!is_array($requested) || $requested === []) {
            throw new CashRegisterException('VALIDATION_FAILED', 'lines : au moins une ligne {orderItemId, quantity}', 422, [['path' => 'lines', 'message' => 'au moins une ligne attendue']]);
        }
        $returned = $this->emProvider->getEntityManager()->getRepository(PosReturn::class)->returnedQuantities($original);
        $itemsTotal = $this->itemsTotal($original);
        $byId = [];
        foreach ($original->getOrderItems() as $item) {
            $byId[$item->getId()] = $item;
        }
        $lines = [];
        $subtotal = 0;
        $taxSums = [];
        $wanted = [];
        foreach ($requested as $i => $row) {
            if (!is_array($row) || !is_numeric($row['orderItemId'] ?? null) || !is_numeric($row['quantity'] ?? null) || (int) $row['quantity'] <= 0) {
                throw new CashRegisterException('VALIDATION_FAILED', "lines[$i] : {orderItemId, quantity > 0} attendu", 422, [['path' => "lines[$i]", 'message' => '{orderItemId, quantity > 0}']]);
            }
            $wanted[(int) $row['orderItemId']] = ($wanted[(int) $row['orderItemId']] ?? 0) + (int) $row['quantity'];
        }
        foreach ($wanted as $itemId => $qty) {
            /** @var ?OrderItems $item */
            $item = $byId[$itemId] ?? null;
            if ($item === null) {
                throw new CashRegisterException('RETURN_LINE_UNKNOWN', 'Ligne inconnue sur cette vente', 422, [], ['orderItemId' => $itemId, 'orderId' => $original->getId()]);
            }
            $returnable = (int) $item->getQuantity() - ($returned[$itemId] ?? 0);
            if ($qty > $returnable) {
                throw new CashRegisterException('RETURN_QUANTITY_EXCEEDED', 'Quantité retournée supérieure à la quantité vendue moins le déjà retourné', 422, [], ['orderItemId' => $itemId, 'requested' => $qty, 'returnable' => $returnable]);
            }
            // Net payé des unités retournées (F1 : total_price net, cumul exact) ; prix unitaire de la ligne de retour = net moyen
            $net = self::taxForQuantity((int) round((float) $item->getTotalPrice()), (int) $item->getQuantity(), $returned[$itemId] ?? 0, $qty);
            $lines[] = ['item' => $item, 'variant' => $item->getProductVariant(), 'quantity' => $qty, 'unitPrice' => intdiv($net, $qty), 'net' => $net];
            $subtotal += $net;
            // Taxes : part de la ligne dans la vente, répartie en cumul (déjà retourné + ce retour) : la somme des retours d'une
            // ligne égale exactement sa part, sans dérive d'arrondi
            foreach ($this->lineTaxShare($original, $item, $itemsTotal) as $tax) {
                $taxSums[$tax['label']] ??= ['label' => $tax['label'], 'amount' => 0, 'tax' => $tax['tax']];
                $taxSums[$tax['label']]['amount'] += self::taxForQuantity($tax['amount'], (int) $item->getQuantity(), $returned[$itemId] ?? 0, $qty);
            }
        }

        return [$lines, $subtotal, array_values($taxSums)];
    }

    /** Retour sans ticket : articles aux prix et taxes actuels du magasin (devis du serveur) */
    private function linesFromCatalog(mixed $items): array
    {
        if (!is_array($items) || $items === []) {
            throw new CashRegisterException('VALIDATION_FAILED', 'items : au moins un article {productVariantId, quantity} pour un retour sans ticket', 422, [['path' => 'items', 'message' => 'au moins un article attendu']]);
        }
        try {
            $quote = $this->quotes->quote(CartQuoteInputDto::fromArray(['items' => $items, 'shippingAddress' => $this->taxAddress->forStore()]));
        } catch (CartQuoteException $e) {
            throw new CashRegisterException('QUOTE_INVALID', $e->getMessage(), 400);
        }
        $em = $this->emProvider->getEntityManager();
        $lines = [];
        foreach ($quote['lines'] as $line) {
            if (($line['kind'] ?? 'sale') !== 'sale') {
                continue;
            }
            $lines[] = ['item' => null, 'variant' => $em->find(ProductVariant::class, (int) $line['productVariantId']), 'quantity' => (int) $line['quantity'], 'unitPrice' => (int) $line['unitPrice'], 'net' => (int) $line['total']];
        }
        $taxes = array_map(fn ($t) => ['label' => (string) ($t['label'] ?? 'Taxe'), 'amount' => (int) $t['amount'], 'tax' => isset($t['taxId']) ? $em->find(Tax::class, (int) $t['taxId']) : null], $quote['taxes'] ?? []);

        return [$lines, (int) $quote['subtotal'], $taxes];
    }

    /** Part de chaque taxe de la vente revenant à une ligne (au prorata de son total dans les articles) */
    private function lineTaxShare(Order $order, OrderItems $item, int $itemsTotal): array
    {
        // Article libre non taxable (F7) : aucune part de taxe ; les autres lignes se partagent les taxes de la vente
        $lineTotal = $item->isTaxable() ? (int) round((float) $item->getTotalPrice()) : 0;
        $out = [];
        foreach ($order->getOrderTaxes() as $orderTax) {
            $amount = (int) round((float) $orderTax->getAmount());
            $out[] = ['label' => (string) ($orderTax->getTax()?->getName() ?? 'Taxe'), 'tax' => $orderTax->getTax(), 'amount' => $itemsTotal > 0 ? (int) round($amount * $lineTotal / $itemsTotal) : 0];
        }

        return $out;
    }

    /** Taxe d'un retour de $qty unités sur une ligne de $sold unités dont $before sont déjà retournées (cumul, arrondi au cent) */
    public static function taxForQuantity(int $lineTax, int $sold, int $before, int $qty): int
    {
        if ($sold <= 0) {
            return 0;
        }

        return (int) round($lineTax * min($sold, $before + $qty) / $sold) - (int) round($lineTax * min($sold, $before) / $sold);
    }

    /** Total des lignes taxables (assiette des taxes ; une ligne libre non taxable, F7, n'en fait pas partie) */
    private function itemsTotal(Order $order): int
    {
        return (int) array_sum(array_map(fn (OrderItems $i) => $i->isTaxable() ? (int) round((float) $i->getTotalPrice()) : 0, $order->getOrderItems()->toArray()));
    }

    /** @return array{0: int, 1: int, 2: ?PosTerminalPayment} part carte, part espèces exacte, paiement terminal à rembourser */
    private function split(?Order $original, string $method, int $toRefund, array $settings): array
    {
        if ($toRefund <= 0) {
            return [0, 0, null];
        }
        $cardPaid = 0;
        if ($original) {
            foreach ($original->getPayments() as $payment) {
                $amount = (int) round((float) $payment->getAmount());
                if ($amount > 0 && $payment->getPaymentMethod()?->getId() === 1) {
                    $cardPaid += $amount;
                }
            }
            $cardPaid -= $this->emProvider->getEntityManager()->getRepository(PosReturn::class)->refundedAmounts($original)['card'];
        }
        $terminal = $original ? $this->terminalPayments->findByOrder((int) $original->getId()) : null;
        if ($terminal !== null && $terminal->getStatus() !== PosTerminalPayment::CAPTURED) {
            $terminal = null;
        }
        // Interac en deux temps : le remboursement déjà présenté sur le lecteur, pas encore rattaché à un retour, reste disponible pour ce retour
        $pendingReaderRefund = $terminal && $terminal->isInterac() && $terminal->getRefundStatus() === PosTerminalPayment::REFUND_SUCCEEDED && $terminal->getRefundId() !== null
            && !$this->emProvider->getEntityManager()->getRepository(PosReturn::class)->findByTerminalRefund($terminal->getRefundId()) ? (int) $terminal->getRefundAmount() : 0;
        $cardAvailable = max(0, $terminal ? min($cardPaid, $terminal->getRefundable() + $pendingReaderRefund) : $cardPaid);
        switch ($method) {
            case PosReturn::METHOD_CASH:
                if ($cardPaid > 0 && !$settings['cashRefundForCardSale']) {
                    throw new CashRegisterException('CASH_REFUND_FORBIDDEN', 'Remboursement en espèces d\'une vente payée par carte refusé sur ce site', 409, [], ['cardPaid' => $cardPaid]);
                }

                return [0, $toRefund, null];
            case PosReturn::METHOD_CARD:
            case PosReturn::METHOD_DECLARED_CARD:
                if ($original === null || $cardAvailable < $toRefund) {
                    throw new CashRegisterException('REFUND_METHOD_INVALID', 'Montant carte remboursable insuffisant sur cette vente', 422, [], ['cardAvailable' => $cardAvailable, 'amount' => $toRefund]);
                }

                return [$toRefund, 0, $method === PosReturn::METHOD_CARD ? $terminal : null];
            default: // moyen d'origine : carte d'abord, puis espèces
                $card = min($toRefund, $cardAvailable);

                return [$card, $toRefund - $card, $card > 0 ? $terminal : null];
        }
    }

    private function reason(array $input, array $settings): array
    {
        $code = is_string($input['reasonCode'] ?? null) ? trim($input['reasonCode']) : null;
        $text = is_string($input['reasonText'] ?? null) ? trim($input['reasonText']) : null;
        if ($code !== null && $code !== '' && !in_array($code, array_column($settings['reasons'], 'code'), true)) {
            throw new CashRegisterException('RETURN_REASON_INVALID', 'Motif inconnu : voir settings.returns.reasons', 422, [], ['reasonCode' => $code, 'reasons' => array_column($settings['reasons'], 'code')]);
        }
        if (($code === null || $code === '') && ($text === null || $text === '')) {
            throw new CashRegisterException('RETURN_REASON_REQUIRED', 'Un motif de retour est requis (reasonCode ou reasonText)', 422);
        }

        return ['code' => $code !== '' ? $code : null, 'text' => $text !== '' ? $text : null];
    }
}
