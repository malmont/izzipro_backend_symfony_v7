<?php
namespace App\UseCase\OrderUseCase;

use App\Dto\CreateOrderDTO;
use App\Dto\CreateOrderMultiPaymentDTO;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;
use App\Dto\ICreateOrderDTO; 

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

    public function execute(ICreateOrderDTO $orderDTO): JsonResponse
    {
        $this->em->getConnection()->beginTransaction();
        $caisseAmount = 0;
        try {
            $user = $this->security->getUser();
            $typeOrderId = $orderDTO->getTypeOrder();
            $statusPaymentId = 2;

            // Pass the entire DTO to the CreateOrderCommandUseCase
            $order = $this->createOrderCommandUseCase->execute($orderDTO, $user);
            if ($order instanceof JsonResponse) {
                throw new \Exception('Order creation failed');
            }

            try {
                $subtotal = $this->processOrderItemsUseCase->execute($order, $orderDTO->getItems(), $this->updateStockAndInventoryUseCase, $typeOrderId, $orderDTO->getPriceShipping());
            } catch (BadRequestHttpException $e) {
                throw new \Exception($e->getMessage());
            } catch (\Exception $e) {
                throw new \Exception('Insufficient stock for product variant');
            }
            
            $subtotal = $typeOrderId === 1 ? $subtotal : -$subtotal;
            ;
            $totalTax = $this->calculateTaxesUseCase->execute($order, $subtotal);
            if ($totalTax instanceof JsonResponse) {
                throw new \Exception('Tax calculation failed');
            }
            $totalAmount = $this->calculateTotalAmountUseCase->execute($subtotal, $totalTax);
            $paymentTypeId = $typeOrderId === 1 ? 2 : 1;

            if ($orderDTO instanceof CreateOrderMultiPaymentDTO) {
                foreach ($orderDTO->getPaymentMethods() as $paymentMethod) {
                    $orderDtoValue = $orderDTO;  // 🟢 On initialise à chaque boucle
            
                    if ($paymentMethod->getType() == 2) {
                        $caisseAmount += $paymentMethod->getAmount();
                        $orderDtoValue = null;  // 🔴 Mettre à null uniquement si type == 2
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
                        $orderDtoValue  // ✅ Null si type == 2, sinon $orderDTO
                    );
                }
            }
             else {
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
            // $this->paymentHandlerUseCase->handlePayment($order, $totalAmount, $orderDTO->getPaymentMethod(), $paymentTypeId, $statusPaymentId);

            $order->setSubTotal($subtotal);
            $order->setTotalTax($totalTax);
            $order->setTotalAmount($totalAmount);

            $this->em->persist($order);
            $caisseAmount=$caisseAmount*100;
            if ($order->getOrderSource()->getId() === 2) {
                $transactionTypeId = $typeOrderId === 1 ? 1 : 2;
                $this->handleCaisseTransactionUseCase->execute($order, $user, $totalAmount, $transactionTypeId,[],$caisseAmount);
            }

            $this->em->flush();
            $this->em->getConnection()->commit();

            return new JsonResponse([
                'message' => 'Order created successfully',
                'orderId' => $order->getId(),
            ], 201);
        } catch (\Exception $e) {
            $this->em->getConnection()->rollBack();
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
    }
}
