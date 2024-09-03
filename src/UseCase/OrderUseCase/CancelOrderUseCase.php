<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\StatusCommande;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;
use Exception;

class CancelOrderUseCase
{
    private $paymentHandlerUseCase;
    private $updateStockAndInventoryUseCase;
    private $handleCaisseTransactionUseCase;
    private $em;

    public function __construct(
        PaymentHandlerUseCase $paymentHandlerUseCase,
        UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase,
        HandleCaisseTransactionUseCase $handleCaisseTransactionUseCase,
        EntityManagerInterface $em
    ) {
        $this->paymentHandlerUseCase = $paymentHandlerUseCase;
        $this->updateStockAndInventoryUseCase = $updateStockAndInventoryUseCase;
        $this->handleCaisseTransactionUseCase = $handleCaisseTransactionUseCase;
        $this->em = $em;
    }

    public function execute(int $orderId): JsonResponse
    {
        // Démarrer une transaction
        $this->em->beginTransaction();

        try {
            // Récupérer la commande par son ID
            $order = $this->em->getRepository(Order::class)->find($orderId);
            if (!$order) {
                throw new Exception('Order not found');
            }
            
            $paymentTypeId = 1; 
            $statusPaymentId = 2;
            $refundAmount = $order->getTotalAmount();

            // Vérifier si la commande peut être annulée (ex: déjà livrée ?)
            $currentStatus = $order->getStatus();
            if (in_array($currentStatus->getId(), [5, 6])) {
                throw new Exception('Order cannot be canceled after it has been shipped or delivered');
            }

            // Mettre à jour le statut de la commande à "Annulé"
            $cancelStatus = $this->em->getRepository(StatusCommande::class)->find(7);
            $order->setStatus($cancelStatus);

            // Remboursement du paiement
            $this->paymentHandlerUseCase->handlePayment($order, -$refundAmount, null, $paymentTypeId, $statusPaymentId);

            // Mise à jour des mouvements de stock
            foreach ($order->getOrderItems() as $orderItem) {
                $this->updateStockAndInventoryUseCase->execute($orderItem->getProductVariant(), $orderItem->getQuantity(), true);
            }

            // Annulation des taxes associées
            foreach ($order->getOrderTaxes() as $orderTax) {
                $orderTax->setAmount(-abs($orderTax->getAmount()));
            }
            
            $transactionTypeId = 2;
            // Si l'ordre provient de la caisse, créer une transaction de remboursement
            if ($order->getOrderSource()->getId() === 2) {
                $this->handleCaisseTransactionUseCase->execute($order, $order->getUserId(), -$refundAmount, $transactionTypeId);
            }

            // Valider la transaction
            $this->em->flush();
            $this->em->commit();

            return new JsonResponse(['message' => 'Order canceled and refunded successfully'], 200);
        } catch (Exception $e) {
            // Annuler la transaction en cas d'erreur
            $this->em->rollback();

            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}
