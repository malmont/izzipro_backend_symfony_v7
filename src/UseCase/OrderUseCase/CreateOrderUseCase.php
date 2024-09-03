<?php
namespace App\UseCase\OrderUseCase;

use App\Dto\CreateOrderDTO;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;

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

    public function execute(CreateOrderDTO $orderDTO): JsonResponse
    {
        $this->em->getConnection()->beginTransaction();

        try {
            $user = $this->security->getUser();
            $typeOrderId = $orderDTO->getTypeOrder();
            $statusPaymentId = 2;

            // Pass the entire DTO to the CreateOrderCommandUseCase
            $order = $this->createOrderCommandUseCase->execute($orderDTO, $user);
            if ($order instanceof JsonResponse) {
                throw new \Exception('Order creation failed');
            }

            $subtotal = $this->processOrderItemsUseCase->execute($order, $orderDTO->getItems(), $this->updateStockAndInventoryUseCase, $typeOrderId);
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
            $this->paymentHandlerUseCase->handlePayment($order, $totalAmount, $orderDTO->getPaymentMethod(), $paymentTypeId, $statusPaymentId);

            $order->setSubTotal($subtotal);
            $order->setTotalTax($totalTax);
            $order->setTotalAmount($totalAmount);

            $this->em->persist($order);

            if ($order->getOrderSource()->getId() === 2) {
                $transactionTypeId = $typeOrderId === 1 ? 1 : 2;
                $this->handleCaisseTransactionUseCase->execute($order, $user, $totalAmount, $transactionTypeId);
            }

            $this->em->flush();
            $this->em->getConnection()->commit();

            return new JsonResponse(['message' => 'Order created successfully'], 201);
        } catch (\Exception $e) {
            $this->em->getConnection()->rollBack();
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
    }
}
