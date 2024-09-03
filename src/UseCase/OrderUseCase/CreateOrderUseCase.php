<?php
namespace App\UseCase\OrderUseCase;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;
use Symfony\Component\Security\Core\Security;

class CreateOrderUseCase
{
    private $createOrderCommandUseCase;
    private $processOrderItemsUseCase;
    private $updateStockAndInventoryUseCase;
    private $calculateTaxesUseCase;
    private $paymentHandlerUseCase;
    private $calculateTotalAmountUseCase;
    private $handleCaisseTransactionUseCase;
    private $em;
    private $security;

    public function __construct(
        CreateOrderCommandUseCase $createOrderCommandUseCase,
        ProcessOrderItemsUseCase $processOrderItemsUseCase,
        UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase,
        CalculateTaxesUseCase $calculateTaxesUseCase,
        PaymentHandlerUseCase $paymentHandlerUseCase,
        CalculateTotalAmountUseCase $calculateTotalAmountUseCase,
        HandleCaisseTransactionUseCase $handleCaisseTransactionUseCase,
        EntityManagerInterface $em,
        Security $security
    ) {
        $this->createOrderCommandUseCase = $createOrderCommandUseCase;
        $this->processOrderItemsUseCase = $processOrderItemsUseCase;
        $this->updateStockAndInventoryUseCase = $updateStockAndInventoryUseCase;
        $this->calculateTaxesUseCase = $calculateTaxesUseCase;
        $this->paymentHandlerUseCase = $paymentHandlerUseCase;
        $this->calculateTotalAmountUseCase = $calculateTotalAmountUseCase;
        $this->handleCaisseTransactionUseCase = $handleCaisseTransactionUseCase;
        $this->em = $em;
        $this->security = $security;
    }

    public function execute(Request $request): JsonResponse
    {
        $this->em->getConnection()->beginTransaction(); // Démarrage de la transaction

        try {
            $data = json_decode($request->getContent(), true);
            $user = $this->security->getUser();
            $statusPaymentId = 2;

            $typeOrderId = $data['typeOrder'] ?? 1;

            $order = $this->createOrderCommandUseCase->execute($data, $user, $typeOrderId);
            if ($order instanceof JsonResponse) {
                throw new \Exception('Order creation failed');
            }

            $subtotal = $this->processOrderItemsUseCase->execute($order, $data['items'], $this->updateStockAndInventoryUseCase, $typeOrderId);
            if ($subtotal instanceof JsonResponse) {
                throw new \Exception('Order items processing failed');
            }
            $subtotal = $typeOrderId === 1 ? $subtotal : -$subtotal;

            $totalTax = $this->calculateTaxesUseCase->execute($order, $subtotal);
            if ($totalTax instanceof JsonResponse) {
                throw new \Exception('Tax calculation failed');
            }

            $totalAmount = $this->calculateTotalAmountUseCase->execute($subtotal, $totalTax);
            $paymentTypeId = $typeOrderId === 1 ? 2 : 1;
            $this->paymentHandlerUseCase->handlePayment($order, $totalAmount, $data['paymentMethod'], $paymentTypeId, $statusPaymentId);

            $order->setSubTotal($subtotal);
            $order->setTotalTax($totalTax);
            $order->setTotalAmount($totalAmount);

            $this->em->persist($order);

            // Gestion de la caisse si la commande provient de la caisse
            if ($order->getOrderSource()->getId() === 2) {
                $transactionTypeId = $typeOrderId === 1 ? 1 : 2;
                $this->handleCaisseTransactionUseCase->execute($order, $user, $totalAmount, $transactionTypeId);
            }

            $this->em->flush(); // Validation des opérations
            $this->em->getConnection()->commit(); // Confirmation de la transaction

            return new JsonResponse(['message' => 'Order created successfully'], 201);
        } catch (\Exception $e) {
            $this->em->getConnection()->rollBack(); // Annulation de la transaction en cas d'erreur
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
    }
}
