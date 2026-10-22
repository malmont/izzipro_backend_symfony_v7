<?php

namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\StatusCommande;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use App\Services\CaisseService\CaisseService;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\CashRegisterResolver;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;
use Exception;

// Assurez-vous d'importer les autres UseCases si ce n'est pas déjà fait
use App\UseCase\OrderUseCase\PaymentHandlerUseCase;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;

class CancelOrderUseCase
{
    private PaymentHandlerUseCase $paymentHandlerUseCase;
    private UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase;
    private HandleCaisseTransactionUseCase $handleCaisseTransactionUseCase;
    private TenantEntityManagerProvider $emProvider;

    public function __construct(
        PaymentHandlerUseCase $paymentHandlerUseCase,
        UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase,
        HandleCaisseTransactionUseCase $handleCaisseTransactionUseCase,
        TenantEntityManagerProvider $emProvider,
        private readonly CaisseService $caisseService,
        private readonly CashRegisterResolver $cashRegisters,
        private readonly \App\Services\CaisseService\CashRoundingService $cashRoundingService,
        private readonly \App\Services\PosAuditService\PosReceiptService $receipts,
        private readonly \App\Services\PosAuditService\PosAuditService $audit,
        private readonly \App\Services\PosAuditService\PosApprovalService $approvals,
        private readonly \App\Services\PosAuditService\PosIdempotencyService $idempotency,
        private readonly \App\Services\PosTerminalService\PosTerminalPaymentService $terminalPayments,
        private readonly \App\Services\PosAuditService\PosApprovalContext $approvalContext
    ) {
        $this->paymentHandlerUseCase = $paymentHandlerUseCase;
        $this->updateStockAndInventoryUseCase = $updateStockAndInventoryUseCase;
        $this->handleCaisseTransactionUseCase = $handleCaisseTransactionUseCase;
        $this->emProvider = $emProvider;
    }

    /** @param ?int $cashRegisterId poste du remboursement d'une vente POS si la session d'origine est fermée (15/10/2026) */
    public function execute(int $orderId, ?int $paymentMethod = null, ?int $cashRegisterId = null, ?\App\Entity\User $refundedBy = null, ?string $terminalRefundId = null): JsonResponse
    {
        // On récupère l'EM du tenant une seule fois
        $em = $this->emProvider->getEntityManager();

        // La gestion de la transaction se fait sur la connexion de l'EM du tenant
        $em->getConnection()->beginTransaction();

        $caisseAmount = 0;
        try {
            // On récupère les entités via le bon EM
            $order = $em->getRepository(Order::class)->find($orderId);
            if (!$order) {
                throw new Exception('Order not found');
            }
            // Vente au comptoir remboursée par un caissier : validation d'un responsable selon les réglages, consommée dans
            // cette transaction (C3). Commande web : inchangé.
            if ($order->getOrderSource()?->getId() === 2 && $refundedBy instanceof \App\Entity\User) {
                // Remboursement Interac déjà présenté sur le terminal pour cette vente et validé par (ou fait par) un responsable (C4,
                // 24/10/2026) : l'annulation rejouée avec ce terminalRefundId n'exige pas une seconde validation ; approvedBy repris
                $priorRefund = $terminalRefundId !== null ? $this->terminalPayments->findByOrder((int) $order->getId()) : null;
                if ($priorRefund && $priorRefund->getRefundId() === $terminalRefundId && $priorRefund->getRefundStatus() === \App\Entity\PosTerminalPayment::REFUND_SUCCEEDED && $priorRefund->getRefundApprovedBy() !== null) {
                    $this->approvalContext->set($priorRefund->getRefundApprovedBy());
                } else {
                    $this->approvals->requireFor(\App\Services\PosAuditService\PosApprovalService::REFUND, $refundedBy, ['orderId' => (int) $order->getId()], (int) round((float) $order->getTotalAmount()));
                }
            }

            $paymentTypeId = 1;
            $statusPaymentId = 2;
            $refundAmount = $order->getTotalAmount();
            // Montants d'origine (remis à 0 plus bas) : conservés au journal de caisse et sur l'avoir (C2)
            $before = [
                'totalAmount' => (int) round((float) $order->getTotalAmount()), 'subTotal' => (int) round((float) $order->getSubTotal()),
                'totalTax' => (int) round((float) $order->getTotalTax()), 'status' => $order->getStatus()?->getId(),
                'taxes' => array_values(array_map(fn ($t) => ['label' => $t->getTax()?->getName(), 'amount' => (int) round((float) $t->getAmount())], $order->getOrderTaxes()->toArray())),
            ];
            $creditNote = null;

            // Vérifier si la commande peut être annulée
            $currentStatus = $order->getStatus();
            if (in_array($currentStatus->getId(), [5, 6])) { // Supposons 5=Expédiée, 6=Livrée
                throw new Exception('Order cannot be canceled after it has been shipped or delivered');
            }

            // Mettre à jour le statut de la commande à "Annulé" (ID 7)
            $cancelStatus = $em->getRepository(StatusCommande::class)->find(7);
            if (!$cancelStatus) {
                throw new Exception('Cancel status not found in database');
            }
            $order->setStatus($cancelStatus);
            $order->setStatusUpdatedAt(new \DateTime());
            $order->setTotalAmount(0);
            $order->setSubTotal(0);
            $order->setTotalTax(0);

            // Remboursement en espèces d'une vente au comptoir (19/10/2026) : une vente payée entièrement en espèces rend
            // exactement les espèces encaissées (arrondies ou non) ; sinon le montant rendu est arrondi comme à
            // l'encaissement (5 ¢ en CAD). Enregistré comme paiement en espèces. Toute autre annulation : inchangée.
            $posCashRefund = $order->getOrderSource()?->getId() === 2 && $paymentMethod == 2;
            $cashRefund = null;
            if ($posCashRefund) {
                $cashReceived = $otherReceived = 0;
                foreach ($order->getPayments() as $payment) {
                    $amount = (int) round((float) $payment->getAmount());
                    if ($amount > 0) {
                        $payment->getPaymentMethod()?->getId() === 2 ? $cashReceived += $amount : $otherReceived += $amount;
                    }
                }
                $cashRefund = $cashReceived > 0 && $otherReceived === 0 ? $cashReceived : $this->cashRoundingService->round((int) round($refundAmount));
            }

            // Remboursement du paiement
            if ($posCashRefund) {
                $this->paymentHandlerUseCase->handlePayment($order, -$cashRefund, 2, $paymentTypeId, $statusPaymentId, new \DateTime(), null);
            } else {
                $this->paymentHandlerUseCase->handlePayment($order, -$refundAmount, null, $paymentTypeId, $statusPaymentId, new \DateTime(), null);
            }

            // Mise à jour des mouvements de stock (réintégration des quantités)
            foreach ($order->getOrderItems() as $orderItem) {
                $this->updateStockAndInventoryUseCase->execute($orderItem->getProductVariant(), $orderItem->getQuantity(), true);
            }

            // Annulation des taxes associées
            foreach ($order->getOrderTaxes() as $orderTax) {
                $orderTax->setAmount(0);
                // Pas besoin de persister orderTax, car il est lié à Order qui sera flushé
            }

            if ($paymentMethod == 2) { // Supposons que 2 = Espèces
                $caisseAmount += $posCashRefund ? $cashRefund : $refundAmount;
            }
            $transactionTypeId = 2; // Type de transaction "Remboursement"
            if ($order->getOrderSource()->getId() === 2) { // 2 = vente POS : seule à toucher une caisse (une commande web ne change pas)
                // Poste de la vente d'origine si sa session est ouverte, sinon le poste demandé s'il est ouvert, sinon règle du poste unique
                $caisse = $this->caisseService->getOpenCaisseOfOrder($order)
                    ?? ($cashRegisterId !== null ? $this->cashRegisters->openSession($this->cashRegisters->resolve($cashRegisterId)) : null)
                    ?? $this->cashRegisters->requireOpenSession(null, 409);
                $this->receipts->lockRegister($caisse->getCashRegister()); // verrou du poste avant celui de la session (C2)
                // Vente payée sur le terminal Stripe (C4), remboursée autrement qu'en espèces : remboursement Stripe de la part
                // carte dans cette transaction (carte de crédit en ligne ; Interac : déjà présenté sur le lecteur, sinon 409)
                $terminal = $this->terminalPayments->findByOrder((int) $order->getId());
                if ($terminal !== null && $terminal->getStatus() === \App\Entity\PosTerminalPayment::CAPTURED && $paymentMethod != 2) {
                    $this->terminalPayments->refundForCancel($terminal, $terminalRefundId, $refundedBy);
                }
                $this->handleCaisseTransactionUseCase->execute($caisse, $order, $refundedBy ?? $order->getUserId(), $refundAmount, $transactionTypeId, [], $caisseAmount,
                    $posCashRefund ? -($cashRefund - (int) round($refundAmount)) : null);
                // Avoir (suite continue par poste) et entrée au journal, avec les montants d'origine (C2)
                $creditNote = $this->receipts->issue($order, $caisse, \App\Entity\PosReceipt::CREDIT_NOTE, -(int) round((float) $refundAmount));
                $this->audit->record(\App\Services\PosAuditService\PosAuditService::REFUND, [
                    'kind' => 'cancellation', 'reference' => $order->getReference(),
                    'creditNoteNumber' => $creditNote->getNumber(),
                    'refundAmount' => (int) round((float) $refundAmount), 'paymentMethod' => $paymentMethod,
                    'cashRefund' => $posCashRefund ? $cashRefund : 0,
                    'terminalRefund' => isset($terminal) && $terminal !== null && $terminal->getRefundStatus() !== null ? ['paymentId' => $terminal->getId(), 'refundId' => $terminal->getRefundId(), 'amount' => $terminal->getRefundAmount(), 'paymentMethodType' => $terminal->getPaymentMethodType()] : null,
                    'before' => $before, 'after' => ['totalAmount' => 0, 'subTotal' => 0, 'totalTax' => 0, 'status' => 7],
                ], $caisse, null, 'order', $order->getId(), $refundedBy);
                // Clé d'idempotence validée avec le remboursement, réponse gardée dans le même ordre SQL (C5)
                $this->idempotency->attach('cancel', $order->getId(), ['message' => 'Order canceled and refunded successfully',
                    'creditNoteNumber' => $creditNote->getNumber(), 'creditNoteReference' => $creditNote->getCashRegister()->receiptReference($creditNote->getNumber()), 'barcode' => \App\Services\PosReturnService\PosReturnService::barcode($creditNote), 'cashRegister' => ['id' => $creditNote->getCashRegister()->getId(), 'name' => $creditNote->getCashRegister()->getName()]], 200);
            }

            // Valider la transaction en base de données
            $em->flush();
            $em->getConnection()->commit();

            // Vente au comptoir : numéro d'avoir et poste en plus (C2) ; annulation web : réponse inchangée
            return new JsonResponse(['message' => 'Order canceled and refunded successfully'] + ($creditNote ? [
                'creditNoteNumber' => $creditNote->getNumber(),
                'creditNoteReference' => $creditNote->getCashRegister()->receiptReference($creditNote->getNumber()), // préfixe du poste (R1)
                'barcode' => \App\Services\PosReturnService\PosReturnService::barcode($creditNote), // code-barres de l'avoir (F2)
                'cashRegister' => ['id' => $creditNote->getCashRegister()->getId(), 'name' => $creditNote->getCashRegister()->getName()],
            ] : []), Response::HTTP_OK);
        } catch (CashRegisterException $e) {
            $em->getConnection()->rollback();

            return new JsonResponse($e->toBody(), $e->status);
        } catch (Exception $e) {
            // En cas d'erreur, on annule toutes les opérations
            $em->getConnection()->rollback();

            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
