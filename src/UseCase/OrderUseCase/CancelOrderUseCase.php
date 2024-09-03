<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;

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
        $paymentTypeId = 2; 
        $statusPaymentId = 2;
        $refundAmount = $order->getTotalAmount();
        // Récupérer la commande par son ID
        $order = $this->em->getRepository(Order::class)->find($orderId);
        if (!$order) {
            return new JsonResponse(['error' => 'Order not found'], 404);
        }

        // Vérifier si la commande peut être annulée (ex: déjà livrée ?)
        $currentStatus = $order->getStatus();
        if (in_array($currentStatus->getId(), [5, 6])) {
            return new JsonResponse(['error' => 'Order cannot be canceled after it has been shipped or delivered'], 400);
        }

        // Mettre à jour le statut de la commande à "Annulé"
        $cancelStatus = $this->em->getRepository(StatusCommande::class)->find(7);
        $order->setStatus($cancelStatus);

        // Remboursement du paiement
        $this->paymentHandlerUseCase->handlePayment($order, $refundAmount, null, $paymentTypeId, $statusPaymentId);

        // Mise à jour des mouvements de stock
        foreach ($order->getOrderItems() as $orderItem) {
            $this->updateStockAndInventoryUseCase->execute($orderItem->getProductVariant(), $orderItem->getQuantity(), true);
        }

        // Annulation des taxes associées
        foreach ($order->getOrderTaxes() as $orderTax) {
            $this->em->remove($orderTax);
        }

        // Si l'ordre provient de la caisse, créer une transaction de remboursement
        if ($order->getOrderSource()->getId() === 2) {
            $this->handleCaisseTransactionUseCase->execute($order, $order->getUser(), -$order->getTotalAmount(), 'Remboursement');
        }

        $this->em->flush();

        return new JsonResponse(['message' => 'Order canceled and refunded successfully'], 200);
    }
}
