<?php

namespace App\UseCase\OrderUseCase;

use App\Dto\CreateOrderDTO;
use App\Dto\CreateOrderMultiPaymentDTO;
use App\Dto\ICreateOrderDTO;
use App\Services\TenantEntityManagerProvider;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\CashRegisterResolver;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Bundle\SecurityBundle\Security;
use App\Entity\Order;
use App\UseCase\OrderUseCase\CreateOrderCommandUseCase;
use App\UseCase\OrderUseCase\ProcessOrderItemsUseCase;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use App\UseCase\OrderUseCase\CalculateTaxesUseCase;
use App\UseCase\OrderUseCase\PaymentHandlerUseCase;
use App\UseCase\OrderUseCase\CalculateTotalAmountUseCase;
use App\UseCase\Booking\HandleBookingUseCase;

class CreateOrderUseCase
{
    private CreateOrderCommandUseCase $createOrderCommandUseCase;
    private ProcessOrderItemsUseCase $processOrderItemsUseCase;
    private UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase;
    private CalculateTaxesUseCase $calculateTaxesUseCase;
    private PaymentHandlerUseCase $paymentHandlerUseCase;
    private CalculateTotalAmountUseCase $calculateTotalAmountUseCase;
    private HandleCaisseTransactionUseCase $handleCaisseTransactionUseCase;
    private TenantEntityManagerProvider $emProvider;
    private Security $security;
    private HandleBookingUseCase $handleBookingUseCase;

    public function __construct(
        CreateOrderCommandUseCase $createOrderCommandUseCase,
        ProcessOrderItemsUseCase $processOrderItemsUseCase,
        UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase,
        CalculateTaxesUseCase $calculateTaxesUseCase,
        PaymentHandlerUseCase $paymentHandlerUseCase,
        CalculateTotalAmountUseCase $calculateTotalAmountUseCase,
        HandleCaisseTransactionUseCase $handleCaisseTransactionUseCase,
        TenantEntityManagerProvider $emProvider,
        Security $security,
        HandleBookingUseCase $handleBookingUseCase,
        private readonly CashRegisterResolver $cashRegisters,
        private readonly \App\Services\CaisseService\CashRoundingService $cashRoundingService,
        private readonly \App\Services\PosAuditService\PosReceiptService $receipts,
        private readonly \App\Services\PosAuditService\PosAuditService $audit,
        private readonly \App\Services\PosAuditService\PosIdempotencyService $idempotency,
        private readonly \App\Services\PosTerminalService\PosTerminalPaymentService $terminalPayments,
        private readonly \App\Services\PosAuditService\PosSettingsService $posSettings,
        private readonly \App\Services\PosDiscountService\PosDiscountService $discounts,
        private readonly \App\Services\PosLineService\PosLineService $posLines, // article libre et prix manuel (F7)
        private readonly \App\Services\PosAuditService\PosApprovalService $approvals, // validations groupées (P2-a)
        private readonly \App\Services\PosGiftCardService\PosGiftCardService $giftCards, // cartes-cadeaux et avoirs (F9)
        private readonly \App\Services\PosCustomerService\PosCustomerService $customers, // fiche client (F8)
        private readonly \App\Services\PosCustomerService\PosLoyaltyService $loyalty, // fidélité (F8)
        private readonly \App\Services\PosCouponService\PosCouponService $coupons // coupons de fabricant (F17)
    ) {
        $this->createOrderCommandUseCase = $createOrderCommandUseCase;
        $this->processOrderItemsUseCase = $processOrderItemsUseCase;
        $this->updateStockAndInventoryUseCase = $updateStockAndInventoryUseCase;
        $this->calculateTaxesUseCase = $calculateTaxesUseCase;
        $this->paymentHandlerUseCase = $paymentHandlerUseCase;
        $this->calculateTotalAmountUseCase = $calculateTotalAmountUseCase;
        $this->handleCaisseTransactionUseCase = $handleCaisseTransactionUseCase;
        $this->emProvider = $emProvider;
        $this->security = $security;
        $this->handleBookingUseCase = $handleBookingUseCase;
    }

    /** Total (cents) d'une vente au comptoir d'après le devis du serveur, sans rien créer (échange F2) */
    public function quoteTotal(array $items, ?array $ticketDiscount = null): int
    {
        try {
            return (int) $this->terminalPayments->quoteTotal($items, $ticketDiscount);
        } catch (\App\Services\OrderService\CartQuoteException $e) {
            throw new CashRegisterException('QUOTE_INVALID', $e->getMessage(), 400);
        }
    }

    public function execute(ICreateOrderDTO $orderDTO, ?\App\Entity\User $user = null)
    {
        $em = $this->emProvider->getEntityManager();
        $em->getConnection()->beginTransaction();

        $caisseAmount = 0;
        try {
            if (!$user) {
                $user = $this->security->getUser();
            }
            $typeOrderId = $orderDTO->getTypeOrder();
            $statusPaymentId = 2;

            // Retour libre en caisse (create-multi-payment avec typeOrder 2, lot P2-a) : soumis au réglage « retour sans ticket » ;
            // les autres sources (e-commerce, application mobile) sont inchangées
            if ($orderDTO instanceof CreateOrderMultiPaymentDTO && $orderDTO->getOrderSource() === 2 && $typeOrderId !== 1 && !$this->posSettings->settings()['returns']['withoutReceipt']) {
                throw new CashRegisterException('RETURN_WITHOUT_RECEIPT_FORBIDDEN', 'Retour sans ticket refusé sur ce site : passez par le retour sur la vente d\'origine (POST /api/pos/returns)', 409);
            }
            // Remises de caisse (F1), article libre et prix manuel (F7) : vente au comptoir seulement ; structure, motifs, plafonds
            // par rôle et validation C3 AVANT toute écriture ; toutes les validations de la vente demandées en une fois (P2-a)
            $ticketDiscount = $orderDTO instanceof CreateOrderMultiPaymentDTO ? $orderDTO->getTicketDiscount() : null;
            // Fiche client et fidélité (F8) : client rattaché (vente au comptoir seulement) ; points rachetés → remise de ticket calculée
            // par le serveur (valeur du site), hors plafonds caissier ; incompatible avec une remise de ticket saisie
            $customer = null;
            $loyaltyReward = null;
            if ($orderDTO instanceof CreateOrderMultiPaymentDTO && $orderDTO->getCustomerId() !== null) {
                if ($orderDTO->getOrderSource() !== 2) {
                    throw new CashRegisterException('CUSTOMER_NOT_ALLOWED', 'Le rattachement d\'un client de caisse ne s\'applique qu\'aux ventes au comptoir', 409);
                }
                $this->customers->assertEnabled();
                $customer = $this->customers->find($orderDTO->getCustomerId());
            }
            if ($orderDTO instanceof CreateOrderMultiPaymentDTO && $orderDTO->getLoyaltyRedeemPoints() > 0) {
                if ($customer === null || $typeOrderId !== 1) {
                    throw new CashRegisterException('LOYALTY_CUSTOMER_REQUIRED', 'Le rachat de points exige un client rattaché à une vente', 422, [['path' => 'customerId', 'message' => 'requis']]);
                }
                if ($ticketDiscount !== null) {
                    throw new CashRegisterException('LOYALTY_TICKET_DISCOUNT_CONFLICT', 'Un rachat de points et une remise de ticket ne se cumulent pas', 409);
                }
                $points = $orderDTO->getLoyaltyRedeemPoints();
                $amount = $this->loyalty->rewardFor($customer, $points, $this->customers);
                $ticketDiscount = ['type' => 'amount', 'value' => $amount, 'reasonText' => sprintf('Récompense fidélité (%d points)', $points)];
                $orderDTO->setTicketDiscount($ticketDiscount);
                $loyaltyReward = ['points' => $points, 'amount' => $amount];
            }
            // Coupons de fabricant (F17) : parts de type 6, vente au comptoir seulement ; contrôlés AVANT toute validation C3 (site, montant, expiration,
            // doublon de série) ; enregistrés plus bas dans la transaction ; taxes et total inchangés (prix plein), seul le dû diminue
            $couponParts = $orderDTO instanceof CreateOrderMultiPaymentDTO ? array_values(array_filter($orderDTO->getPaymentMethods(), fn ($m) => $m->isCoupon())) : [];
            $couponList = [];
            $couponDemand = null;
            if ($couponParts !== []) {
                if ($orderDTO->getOrderSource() !== 2 || $typeOrderId !== 1) {
                    throw new CashRegisterException('COUPON_PAYMENT_INVALID', 'Un coupon de fabricant ne règle qu\'une vente au comptoir', 409);
                }
                $couponList = $this->coupons->fromParts($couponParts);
                $couponDemand = $this->coupons->demand($user, (int) array_sum(array_column($couponList, 'amount')));
            }
            $discountSummary = null;
            $posQuote = null;
            $hasDiscounts = \App\Services\PosDiscountService\PosDiscountCalculator::hasDiscounts($orderDTO->getItems(), $ticketDiscount);
            $hasLines = \App\Services\PosLineService\PosLineService::hasLines($orderDTO->getItems());
            if ($orderDTO instanceof CreateOrderMultiPaymentDTO && $orderDTO->getOrderSource() === 2 && $typeOrderId === 1 && ($hasDiscounts || $hasLines)) {
                $posQuote = $this->discounts->quote($orderDTO->getItems(), $ticketDiscount);
                $discountSummary = $hasDiscounts ? $posQuote['discounts'] : null;
                $demands = $this->posLines->demands($user, $posQuote);
                // Récompense fidélité (F8) : hors plafonds et validations des remises caissier (règle du programme)
                $capSummary = $discountSummary !== null && $loyaltyReward !== null
                    ? ['lines' => $discountSummary['lines'], 'ticket' => null, 'total' => max(0, $discountSummary['total'] - (int) ($discountSummary['ticket']['amount'] ?? 0)), 'gross' => $discountSummary['gross']]
                    : $discountSummary;
                if ($capSummary !== null && ($d = $this->discounts->demand($user, $capSummary)) !== null) {
                    array_unshift($demands, $d);
                }
                if ($couponDemand !== null) {
                    $demands[] = $couponDemand; // validation groupée (P2-a) : un seul jeton pour remises, lignes et coupons
                }
                $this->approvals->requireAll($demands, $user);
                if ($capSummary !== null) {
                    $this->discounts->checkManagerCap($user, $capSummary);
                }
            } elseif ($hasDiscounts) {
                throw new CashRegisterException('DISCOUNT_NOT_ALLOWED', 'Les remises ne s\'appliquent qu\'aux ventes au comptoir', 409);
            } elseif ($hasLines) {
                throw new CashRegisterException('CUSTOM_ITEM_NOT_ALLOWED', 'L\'article libre et le prix manuel ne s\'appliquent qu\'aux ventes au comptoir', 409);
            } elseif ($couponDemand !== null) {
                $this->approvals->requireAll([$couponDemand], $user); // coupons seuls au-delà du maximum : validation C3 action manufacturer_coupon
            }
            $order = $this->createOrderCommandUseCase->execute($orderDTO, $user);
            if ($order instanceof JsonResponse) {
                throw new \Exception($order->getContent());
            }
            try {
                $subtotal = $this->processOrderItemsUseCase->execute($order, $orderDTO->getItems(), $this->updateStockAndInventoryUseCase, $typeOrderId, $orderDTO->getPriceShipping(), $orderDTO->getCarrierId(), $ticketDiscount);
            } catch (BadRequestHttpException $e) {
                throw new \Exception($e->getMessage());
            } catch (\Exception $e) {
                throw new \Exception('Insufficient stock for product variant');
            }
            $this->handleBookingUseCase->execute($order, $orderDTO->getItems());

            $subtotal = $typeOrderId === 1 ? $subtotal : -$subtotal;

            $totalTax = $this->calculateTaxesUseCase->execute($order, $subtotal);
            if ($totalTax instanceof JsonResponse) {
                throw new \Exception('Tax calculation failed');
            }
            $totalAmount = $this->calculateTotalAmountUseCase->execute($subtotal, $totalTax);

            // Paiement Stripe vérifié (web) ou somme des moyens de paiement (caisse, 15/10/2026) : le montant doit
            // correspondre au total calculé par le serveur (sinon un petit paiement pouvait valider une grosse commande).
            // Tolérance : 1 centime d'arrondi. Sans montant (EasyAdmin) : pas de contrôle, comme avant.
            // Vente en caisse (create-multi-payment) : part espèces arrondie acceptée (5 ¢ en CAD), arrondi gardé sur la
            // commande (19/10/2026). Les autres parcours (web, tunnel Stripe, EasyAdmin) gardent le contrôle ci-dessous.
            $cashRounding = 0;
            // Échange (F2) : le crédit du retour réduit le montant à payer
            $credit = $orderDTO instanceof CreateOrderMultiPaymentDTO ? min($orderDTO->getCreditApplied(), max(0, (int) round($totalAmount))) : 0;
            if ($credit > 0) {
                $order->setCreditApplied($credit);
            }
            if ($orderDTO instanceof CreateOrderMultiPaymentDTO && $orderDTO->getCashCents() !== null) {
                $cashRounding = $this->cashRoundingService->checkCounterPayment((int) round($totalAmount) - $credit, $orderDTO->getCashCents(), (int) $orderDTO->getNonCashCents());
                $order->setCashRounding($cashRounding);
            } elseif ($orderDTO->getVerifiedPaymentAmount() !== null
                && abs((int) round($totalAmount) - $orderDTO->getVerifiedPaymentAmount()) > 1) {
                throw new \Exception(sprintf(
                    'Le montant payé (%.2f) ne correspond pas au total de la commande (%.2f).',
                    $orderDTO->getVerifiedPaymentAmount() / 100,
                    round($totalAmount) / 100
                ));
            }
            $paymentTypeId = $typeOrderId === 1 ? 2 : 1;

            $giftParts = [];
            if ($orderDTO instanceof CreateOrderMultiPaymentDTO) {
                foreach ($orderDTO->getPaymentMethods() as $paymentMethod) {
                    $orderDtoValue = $orderDTO;

                    if ($paymentMethod->isGiftCard()) { // carte-cadeau ou avoir (F9) : débité plus bas, dans la transaction, aucune ligne dans payments
                        if ($orderDTO->getOrderSource() !== 2 || $typeOrderId !== 1) {
                            throw new CashRegisterException('GIFT_CARD_PAYMENT_INVALID', 'Une carte-cadeau ou un avoir ne règle qu\'une vente au comptoir', 409);
                        }
                        $giftParts[] = $paymentMethod;
                        continue;
                    }
                    if ($paymentMethod->isCoupon()) { // coupon de fabricant (F17) : enregistré plus bas, aucune ligne dans payments
                        continue;
                    }
                    if ($paymentMethod->getType() == 2) {
                        $caisseAmount += $paymentMethod->getAmount();
                        $orderDtoValue = null;
                    }

                    $amountPaymentMethod = $typeOrderId === 1
                        ? $paymentMethod->getAmount()
                        : -$paymentMethod->getAmount();

                    $this->paymentHandlerUseCase->handlePayment(
                        $order,
                        $amountPaymentMethod * 100,
                        $paymentMethod->getType(),
                        $paymentTypeId,
                        $statusPaymentId,
                        new \DateTime(),
                        $orderDtoValue
                    );
                }
            } elseif ($orderDTO instanceof CreateOrderDTO) {
                $this->paymentHandlerUseCase->handlePayment(
                    $order,
                    $totalAmount,
                    $orderDTO->getPaymentMethod(),
                    $paymentTypeId,
                    $statusPaymentId,
                    new \DateTime(),
                    $orderDTO
                );
            }

            $order->setSubTotal($subtotal);
            $order->setTotalTax($totalTax);
            $order->setTotalAmount($totalAmount);

            $em->persist($order);

            $caisseAmount = $caisseAmount * 100;
            if ($order->getOrderSource()->getId() === 2) {
                // Vente ou retour en caisse : session ouverte du poste (cashRegisterId de la route caisse, sinon poste
                // unique du site) ; poste sans session ouverte → 409 CASH_REGISTER_CLOSED et la transaction est annulée
                $transactionTypeId = $typeOrderId === 1 ? 1 : 2;
                $caisse = $this->cashRegisters->requireOpenSession($orderDTO instanceof CreateOrderMultiPaymentDTO ? $orderDTO->getCashRegisterId() : null, 409);
                $this->receipts->lockRegister($caisse->getCashRegister()); // verrou du poste avant celui de la session (C2)
                // Parts carte payées sur le terminal Stripe (C4) : paiement réussi, même montant, même poste, jamais rattaché ;
                // capture de l'autorisation (carte de crédit) DANS cette transaction, puis rattachement à la vente
                $terminalPayments = [];
                if ($orderDTO instanceof CreateOrderMultiPaymentDTO && $typeOrderId === 1) {
                    // Moyens de paiement autorisés sur le site (R1) : 422 PAYMENT_METHOD_DISABLED, MULTI_PAYMENT_DISABLED, DECLARED_CARD_BLOCKED
                    $this->posSettings->checkPaymentMethods($orderDTO->getPaymentMethods(), $caisse->getCashRegister());
                    $cardPayments = $em->getRepository(\App\Entity\Payments::class)->findBy(['orderPayment' => $order, 'paymentMethod' => 1], ['id' => 'ASC']);
                    foreach ($orderDTO->getPaymentMethods() as $i => $method) {
                        if ($method->getTerminalPaymentId() === null) {
                            continue;
                        }
                        $terminal = $this->terminalPayments->verifyForSale($method->getTerminalPaymentId(), (int) round($method->getAmount() * 100), $caisse->getCashRegister());
                        $this->terminalPayments->captureForSale($terminal, $order);
                        $terminalPayments[] = $terminal;
                        if ($cardPayment = array_shift($cardPayments)) {
                            $cardPayment->setStripePaymentId($terminal->getStripePaymentIntentId())->setStripeStatus('succeeded')
                                ->setStripeCardBrand($terminal->getCardBrand())->setStripeLast4($terminal->getLast4());
                        }
                    }
                }
                // Cartes-cadeaux et avoirs (F9) : vérifiés et débités ici (ligne verrouillée) ; part portée par order.gift_card_applied
                foreach ($giftParts as $part) {
                    $this->giftCards->redeem($part, $order, $user, $caisse);
                }
                // Coupons de fabricant (F17) : montant ≤ prix de la ligne concernée, écrits ici (ligne verrouillée), part portée par order.manufacturer_coupon_applied
                if ($couponList !== []) {
                    $this->coupons->redeem($couponList, $order, $user, $caisse);
                }
                // Fiche client et fidélité (F8) : client rattaché, points rachetés puis points gagnés sur le net (après remises, hors carte-cadeau)
                $loyalty = ['earned' => 0, 'redeemed' => 0, 'redeemedAmount' => 0];
                if ($customer !== null) {
                    $order->setCustomerId($customer->getId());
                    if ($loyaltyReward !== null) {
                        $this->loyalty->redeem($customer, $loyaltyReward['points'], $loyaltyReward['amount'], $order, $caisse, $user instanceof \App\Entity\User ? $user : null);
                        $loyalty['redeemed'] = $loyaltyReward['points'];
                        $loyalty['redeemedAmount'] = $loyaltyReward['amount'];
                    }
                    if ($typeOrderId === 1) {
                        $loyalty['earned'] = $this->loyalty->earn($order, $customer, $caisse, $user instanceof \App\Entity\User ? $user : null, $this->customers);
                    }
                }
                $this->handleCaisseTransactionUseCase->execute($caisse, $order, $user, $totalAmount, $transactionTypeId, [], $caisseAmount, $orderDTO instanceof CreateOrderMultiPaymentDTO ? $cashRounding : null);
                // Numéro de ticket (vente) ou d'avoir (retour), suite continue par poste, et entrée au journal de caisse (C2)
                $receipt = $this->receipts->issue($order, $caisse, $typeOrderId === 1 ? \App\Entity\PosReceipt::TICKET : \App\Entity\PosReceipt::CREDIT_NOTE, (int) round($totalAmount));
                $this->audit->record($typeOrderId === 1 ? \App\Services\PosAuditService\PosAuditService::SALE : \App\Services\PosAuditService\PosAuditService::REFUND, [
                    'kind' => $typeOrderId === 1 ? 'sale' : 'return',
                    'reference' => $order->getReference(),
                    'receiptKind' => $receipt->getKind(),
                    'receiptNumber' => $receipt->getNumber(),
                    'subTotal' => (int) round((float) $subtotal), 'totalTax' => (int) round((float) $totalTax), 'totalAmount' => (int) round($totalAmount),
                    'cashRounding' => $order->getCashRounding(),
                    'creditApplied' => $order->getCreditApplied(),
                    'giftCardApplied' => $order->getGiftCardApplied(), // cartes-cadeaux et avoirs (F9)
                    'manufacturerCouponApplied' => $order->getManufacturerCouponApplied(), // coupons de fabricant (F17)
                    'customerId' => $customer?->getId(), 'loyalty' => $customer !== null ? $loyalty : null, // fiche client et fidélité (F8)
                    'discountTotal' => $discountSummary['total'] ?? 0,
                    'payments' => array_values(array_map(fn ($p) => ['type' => $p->getPaymentMethod()?->getId(), 'amount' => (int) round((float) $p->getAmount())], $order->getPayments()->toArray())),
                    'terminalPayments' => array_map(fn ($t) => ['paymentId' => $t->getId(), 'stripePaymentIntentId' => $t->getStripePaymentIntentId(), 'amount' => $t->getAmount(), 'paymentMethodType' => $t->getPaymentMethodType(), 'cardBrand' => $t->getCardBrand(), 'last4' => $t->getLast4(), 'applicationFee' => $t->getApplicationFee()], $terminalPayments),
                    'items' => array_values(array_map(fn ($i) => ['productVariantId' => $i->getProductVariant()?->getId(), 'quantity' => $i->getQuantity(), 'totalPrice' => (int) round((float) $i->getTotalPrice())], $order->getOrderItems()->toArray())),
                ] + ($posQuote !== null && ($posQuote['customItems']['count'] ?? 0) > 0 ? [ // articles libres (F7) : libellé, prix saisi, taxable
                    'customItems' => array_values(array_map(fn ($i) => ['label' => $i->getCustomLabel(), 'quantity' => $i->getQuantity(), 'unitPrice' => (int) round((float) $i->getUnitPrice()), 'totalPrice' => (int) round((float) $i->getTotalPrice()), 'taxable' => $i->isTaxable()], array_filter($order->getOrderItems()->toArray(), fn ($i) => $i->isCustom()))),
                    'customItemsTotal' => $posQuote['customItems']['total'],
                ] + ($this->approvals->approvedBy() !== null ? ['approvedBy' => $this->approvals->approvedBy()] : []) : []), $caisse, null, 'order', $order->getId(), $user instanceof \App\Entity\User ? $user : null);
                // Prix manuels (F7) : une entrée PRICE_CHANGED par ligne, avant / après, motif, approvedBy si validation
                foreach ($order->getOrderItems() as $i) {
                    if ($i->hasPriceOverride()) {
                        $this->audit->record(\App\Services\PosAuditService\PosAuditService::PRICE_CHANGED, [
                            'reference' => $order->getReference(), 'receiptNumber' => $receipt->getNumber(), 'orderItemId' => $i->getId(), 'productVariantId' => $i->getProductVariant()?->getId(),
                            'name' => $i->displayName(), 'quantity' => $i->getQuantity(), 'before' => $i->getCatalogUnitPrice(), 'after' => (int) round((float) $i->getUnitPrice()),
                            'difference' => ($i->getCatalogUnitPrice() - (int) round((float) $i->getUnitPrice())) * $i->getQuantity(), 'reason' => ['code' => $i->getPriceReasonCode(), 'text' => $i->getPriceReasonText()],
                        ], $caisse, null, 'order', $order->getId(), $user instanceof \App\Entity\User ? $user : null);
                    }
                }
                if ($discountSummary !== null && $discountSummary['total'] > 0) { // remises accordées (F1) : entrée dédiée, approvedBy si validation
                    $this->audit->record(\App\Services\PosAuditService\PosAuditService::DISCOUNT_APPLIED, [
                        'reference' => $order->getReference(), 'receiptNumber' => $receipt->getNumber(), 'gross' => $discountSummary['gross'], 'total' => $discountSummary['total'],
                        'ticket' => $discountSummary['ticket'], 'lines' => array_values(array_map(fn ($i) => ['productVariantId' => $i->getProductVariant()?->getId(), 'quantity' => $i->getQuantity(), 'gross' => $i->grossTotal(), 'discountAmount' => $i->getDiscountAmount(), 'discounts' => $i->getDiscounts()], array_filter($order->getOrderItems()->toArray(), fn ($i) => $i->getDiscountAmount() > 0))),
                    ], $caisse, null, 'order', $order->getId(), $user instanceof \App\Entity\User ? $user : null);
                }
                $this->idempotency->attach('sale', $order->getId()); // clé d'idempotence validée avec la vente (C5)
            }

            $em->flush();
            $em->getConnection()->commit();
            return $order;
        } catch (CashRegisterException $e) {
            if ($em->getConnection()->isTransactionActive()) {
                $em->getConnection()->rollBack();
            }
            // Coupons de fabricant (F17) : le refus (déjà utilisé, expiré) reste journalisé malgré l'annulation de la transaction
            $this->coupons->journalRefusalAfterRollback($e, $user instanceof \App\Entity\User ? $user : null);
            return new JsonResponse($e->toBody(), $e->status);
        } catch (\Exception $e) {
            if ($em->getConnection()->isTransactionActive()) {
                $em->getConnection()->rollBack();
            }
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
