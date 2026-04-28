<?php

namespace App\UseCase\OrderUseCase;

use App\Dto\CreateOrderDTO;
use App\Dto\CreateOrderMultiPaymentDTO;
use App\Dto\ICreateOrderDTO;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Security;
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
        HandleBookingUseCase $handleBookingUseCase
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
                $subtotal = $this->processOrderItemsUseCase->execute($order, $orderDTO->getItems(), $this->updateStockAndInventoryUseCase, $typeOrderId, $orderDTO->getPriceShipping());
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
                $transactionTypeId = $typeOrderId === 1 ? 1 : 2;
                $this->handleCaisseTransactionUseCase->execute($order, $user, $totalAmount, $transactionTypeId, [], $caisseAmount);
            }

            $em->flush();
            $em->getConnection()->commit();
            return $order;
        } catch (\Exception $e) {
            if ($em->getConnection()->isTransactionActive()) {
                $em->getConnection()->rollBack();
            }
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
