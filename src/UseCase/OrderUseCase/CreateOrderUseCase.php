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
        private readonly \App\Services\PosAuditService\PosIdempotencyService $idempotency
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

            $order = $this->createOrderCommandUseCase->execute($orderDTO, $user);
            if ($order instanceof JsonResponse) {
                throw new \Exception($order->getContent());
            }
            try {
                $subtotal = $this->processOrderItemsUseCase->execute($order, $orderDTO->getItems(), $this->updateStockAndInventoryUseCase, $typeOrderId, $orderDTO->getPriceShipping(), $orderDTO->getCarrierId());
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
            if ($orderDTO instanceof CreateOrderMultiPaymentDTO && $orderDTO->getCashCents() !== null) {
                $cashRounding = $this->cashRoundingService->checkCounterPayment((int) round($totalAmount), $orderDTO->getCashCents(), (int) $orderDTO->getNonCashCents());
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

            if ($orderDTO instanceof CreateOrderMultiPaymentDTO) {
                foreach ($orderDTO->getPaymentMethods() as $paymentMethod) {
                    $orderDtoValue = $orderDTO;

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
                    'payments' => array_values(array_map(fn ($p) => ['type' => $p->getPaymentMethod()?->getId(), 'amount' => (int) round((float) $p->getAmount())], $order->getPayments()->toArray())),
                    'items' => array_values(array_map(fn ($i) => ['productVariantId' => $i->getProductVariant()?->getId(), 'quantity' => $i->getQuantity(), 'totalPrice' => (int) round((float) $i->getTotalPrice())], $order->getOrderItems()->toArray())),
                ], $caisse, null, 'order', $order->getId(), $user instanceof \App\Entity\User ? $user : null);
                $this->idempotency->attach('sale', $order->getId()); // clé d'idempotence validée avec la vente (C5)
            }

            $em->flush();
            $em->getConnection()->commit();
            return $order;
        } catch (CashRegisterException $e) {
            if ($em->getConnection()->isTransactionActive()) {
                $em->getConnection()->rollBack();
            }
            return new JsonResponse($e->toBody(), $e->status);
        } catch (\Exception $e) {
            if ($em->getConnection()->isTransactionActive()) {
                $em->getConnection()->rollBack();
            }
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
