<?php

namespace App\Tests\Unit\UseCase\OrderUseCase;

use App\Dto\CreateOrderMultiPaymentDTO;
use App\Dto\ICreateOrderDTO;
use App\Dto\PaymentMethodDTO;
use App\Entity\Order;
use App\Entity\OrderSource;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\Booking\HandleBookingUseCase;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;
use App\UseCase\OrderUseCase\CalculateTaxesUseCase;
use App\UseCase\OrderUseCase\CalculateTotalAmountUseCase;
use App\UseCase\OrderUseCase\CreateOrderCommandUseCase;
use App\UseCase\OrderUseCase\CreateOrderUseCase;
use App\UseCase\OrderUseCase\PaymentHandlerUseCase;
use App\UseCase\OrderUseCase\ProcessOrderItemsUseCase;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Security;

class CreateOrderUseCaseTest extends TestCase
{
    private $createCommand;
    private $processItems;
    private $stockUpdater;
    private $calcTax;
    private $paymentHandler;
    private $calcTotal;
    private $caisseHandler;
    private $emProvider;
    private $security;
    private $bookingHandler;
    private $entityManager;
    private $connection;
    private $useCase;

    protected function setUp(): void
    {
        $this->createCommand = $this->createMock(CreateOrderCommandUseCase::class);
        $this->processItems = $this->createMock(ProcessOrderItemsUseCase::class);
        $this->stockUpdater = $this->createMock(UpdateStockAndInventoryUseCase::class);
        $this->calcTax = $this->createMock(CalculateTaxesUseCase::class);
        $this->paymentHandler = $this->createMock(PaymentHandlerUseCase::class);
        $this->calcTotal = $this->createMock(CalculateTotalAmountUseCase::class);
        $this->caisseHandler = $this->createMock(HandleCaisseTransactionUseCase::class);
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->security = $this->createMock(Security::class);
        $this->bookingHandler = $this->createMock(HandleBookingUseCase::class);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);

        $this->emProvider->method('getEntityManager')->willReturn($this->entityManager);
        $this->entityManager->method('getConnection')->willReturn($this->connection);

        $this->useCase = new CreateOrderUseCase(
            $this->createCommand,
            $this->processItems,
            $this->stockUpdater,
            $this->calcTax,
            $this->paymentHandler,
            $this->calcTotal,
            $this->caisseHandler,
            $this->emProvider,
            $this->security,
            $this->bookingHandler
        );
    }

    public function testExecuteTransactionRollbackOnException(): void
    {
        $orderDTO = $this->createMock(ICreateOrderDTO::class);

        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->once())->method('isTransactionActive')->willReturn(true);

        $this->security->method('getUser')->willThrowException(new \Exception('Auth Error'));

        $response = $this->useCase->execute($orderDTO);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertStringContainsString('Auth Error', $response->getContent());
    }

    public function testExecuteSuccessStandardOrder(): void
    {
        // 1. Setup DTO and User
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);

        // Use concrete class to allow getPaymentMethod
        $orderDTO = $this->createMock(\App\Dto\CreateOrderDTO::class);
        $orderDTO->method('getTypeOrder')->willReturn(1);
        $orderDTO->method('getItems')->willReturn([]);
        $orderDTO->method('getPriceShipping')->willReturn(10.0);
        $orderDTO->method('getPaymentMethod')->willReturn(123);

        // 2. Setup Order Creation
        $order = $this->createMock(Order::class);
        $this->createCommand->method('execute')->willReturn($order);

        // 3. Process Items and Booking
        $this->processItems->expects($this->once())->method('execute')->willReturn(100.0); // Subtotal
        $this->bookingHandler->expects($this->once())->method('execute');

        // 4. Taxes and Total
        $this->calcTax->expects($this->once())->method('execute')->willReturn(20.0);
        $this->calcTotal->expects($this->once())->method('execute')->with(100.0, 20.0)->willReturn(120.0);

        // 5. Payment Handler (Standard)
        $this->paymentHandler->expects($this->once())->method('handlePayment');

        // 6. Persistence and Source Checking
        $orderSource = $this->createMock(OrderSource::class);
        $orderSource->method('getId')->willReturn(1); // Standard web source
        $order->method('getOrderSource')->willReturn($orderSource);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');
        $this->connection->expects($this->once())->method('commit');

        $result = $this->useCase->execute($orderDTO);
        $this->assertSame($order, $result);
    }

    public function testExecuteSuccessPosOrder(): void
    {
        // 1. Setup DTO and User
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);

        // Use concrete class to allow getPaymentMethod
        $orderDTO = $this->createMock(\App\Dto\CreateOrderDTO::class);
        $orderDTO->method('getTypeOrder')->willReturn(1);
        $orderDTO->method('getItems')->willReturn([]);
        $orderDTO->method('getPriceShipping')->willReturn(null);
        $orderDTO->method('getPaymentMethod')->willReturn(0);

        // 2. Setup Order Creation
        $order = $this->createMock(Order::class);
        // Ensure subTotal/TotalTax/TotalAmount setters are called (implicitly or explicitly mocked)
        $order->expects($this->once())->method('setSubTotal');
        $order->expects($this->once())->method('setTotalTax');
        $order->expects($this->once())->method('setTotalAmount');

        $this->createCommand->method('execute')->willReturn($order);

        $this->processItems->method('execute')->willReturn(50.0);
        $this->calcTax->method('execute')->willReturn(10.0);
        $this->calcTotal->method('execute')->willReturn(60.0);

        // 6. Persistence and Souce Checking - Source 2 = POS
        $orderSource = $this->createMock(OrderSource::class);
        $orderSource->method('getId')->willReturn(2); // POS
        $order->method('getOrderSource')->willReturn($orderSource);

        // Expect Caisse Handler
        $this->caisseHandler->expects($this->once())
            ->method('execute')
            ->with($order, $user, 60.0, 1, [], 0); // 1 = TransactionType (Order), 0 = CaisseAmount (Standard DTO has no amount extraction logic for single payment var in this test)

        $result = $this->useCase->execute($orderDTO);
        $this->assertSame($order, $result);
    }

    public function testExecuteSuccessMultiPaymentOrder(): void
    {
        // 1. Setup DTO and User
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);

        // Mock CreateOrderMultiPaymentDTO
        $orderDTO = $this->createMock(CreateOrderMultiPaymentDTO::class);
        $orderDTO->method('getTypeOrder')->willReturn(1);
        $orderDTO->method('getItems')->willReturn([]);
        $orderDTO->method('getPriceShipping')->willReturn(10.0);
        // Important: getPaymentMethod() should return null (or be compatible with interface)
        $orderDTO->method('getPaymentMethod')->willReturn(null);

        $paymentMethod1 = $this->createMock(PaymentMethodDTO::class);
        $paymentMethod1->method('getType')->willReturn(3); // Some type
        $paymentMethod1->method('getAmount')->willReturn(50.0);

        $paymentMethod2 = $this->createMock(PaymentMethodDTO::class);
        $paymentMethod2->method('getType')->willReturn(4); // Another type
        $paymentMethod2->method('getAmount')->willReturn(70.0); // Total 120

        $orderDTO->method('getPaymentMethods')->willReturn([$paymentMethod1, $paymentMethod2]);

        // 2. Setup Order Creation
        $order = $this->createMock(Order::class);
        $this->createCommand->method('execute')->willReturn($order);

        // 3. Process Items and Booking
        $this->processItems->expects($this->once())->method('execute')->willReturn(100.0);
        $this->bookingHandler->expects($this->once())->method('execute');

        // 4. Taxes and Total
        $this->calcTax->expects($this->once())->method('execute')->willReturn(20.0);
        $this->calcTotal->expects($this->once())->method('execute')->with(100.0, 20.0)->willReturn(120.0);

        // 5. Payment Handler (Called twice)
        $this->paymentHandler->expects($this->exactly(2))->method('handlePayment');

        // 6. Persistence
        $orderSource = $this->createMock(OrderSource::class);
        $orderSource->method('getId')->willReturn(1);
        $order->method('getOrderSource')->willReturn($orderSource);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');
        $this->connection->expects($this->once())->method('commit');

        $result = $this->useCase->execute($orderDTO);
        $this->assertSame($order, $result);
    }
}
