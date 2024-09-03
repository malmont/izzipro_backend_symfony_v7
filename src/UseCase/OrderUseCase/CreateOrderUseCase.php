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
        
        $data = json_decode($request->getContent(), true);
        $user = $this->security->getUser();
        $paymentTypeId = 2; 
        $statusPaymentId = 2;

        $order = $this->createOrderCommandUseCase->execute($data, $user);
        if ($order instanceof JsonResponse) {
            return $order;
        }

        $subtotal = $this->processOrderItemsUseCase->execute($order, $data['items'], $this->updateStockAndInventoryUseCase);
        if ($subtotal instanceof JsonResponse) {
            return $subtotal; // Gestion de l'erreur ici
        }

        $totalTax = $this->calculateTaxesUseCase->execute($order, $subtotal);
        if ($totalTax instanceof JsonResponse) {
            return $totalTax; // Gestion de l'erreur ici
        }

        $totalAmount = $this->calculateTotalAmountUseCase->execute($subtotal, $totalTax);
        $this->paymentHandlerUseCase->handlePayment($order, $totalAmount, $data['paymentMethod'], $paymentTypeId, $statusPaymentId);

          // Assignation des montants à la commande
        $order->setSubTotal($subtotal);
        $order->setTotalTax($totalTax); 
        $order->setTotalAmount($totalAmount);
        
        $this->em->persist($order);
           // Gestion de la caisse si la commande provient de la caisse
        if ($order->getOrderSource()->getId() === 2) {
            $this->handleCaisseTransactionUseCase->execute($order, $user, $totalAmount, 'Vendu');
        }

        // Flushing all persisted entities to the database
        $this->em->flush();

        return new JsonResponse(['message' => 'Order created successfully'], 201);
    }
}
