<?php

namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\StatusCommande;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
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
        TenantEntityManagerProvider $emProvider
    ) {
        $this->paymentHandlerUseCase = $paymentHandlerUseCase;
        $this->updateStockAndInventoryUseCase = $updateStockAndInventoryUseCase;
        $this->handleCaisseTransactionUseCase = $handleCaisseTransactionUseCase;
        $this->emProvider = $emProvider;
    }

    public function execute(int $orderId, int $paymentMethod): JsonResponse
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

            $paymentTypeId = 1; 
            $statusPaymentId = 2;
            $refundAmount = $order->getTotalAmount();

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

            // Remboursement du paiement
            $this->paymentHandlerUseCase->handlePayment($order, -$refundAmount, null, $paymentTypeId, $statusPaymentId, new \DateTime(), null);

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
                $caisseAmount += $refundAmount;
            }
            $transactionTypeId = 2; // Type de transaction "Remboursement"
            if ($order->getOrderSource()->getId() === 2) { // Supposons que 2 = POS
                $this->handleCaisseTransactionUseCase->execute($order, $order->getUser(), $refundAmount, $transactionTypeId, [], $caisseAmount);
            }

            // Valider la transaction en base de données
            $em->flush();
            $em->getConnection()->commit();

            return new JsonResponse(['message' => 'Order canceled and refunded successfully'], Response::HTTP_OK);

        } catch (Exception $e) {
            // En cas d'erreur, on annule toutes les opérations
            $em->getConnection()->rollback();

            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}