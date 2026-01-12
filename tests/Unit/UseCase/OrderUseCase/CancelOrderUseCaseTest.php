<?php

namespace App\Tests\Unit\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\OrderSource;
use App\Entity\OrderTax;
use App\Entity\ProductVariant;
use App\Entity\StatusCommande;
use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\CaisseUseCase\HandleCaisseTransactionUseCase;
use App\UseCase\OrderUseCase\CancelOrderUseCase;
use App\UseCase\OrderUseCase\PaymentHandlerUseCase;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class CancelOrderUseCaseTest extends TestCase
{
    private $paymentHandler;
    private $stockUpdater;
    private $caisseHandler;
    private $emProvider;
    private $entityManager;
    private $connection;
    private $useCase;

    protected function setUp(): void
    {
        $this->paymentHandler = $this->createMock(PaymentHandlerUseCase::class);
        $this->stockUpdater = $this->createMock(UpdateStockAndInventoryUseCase::class);
        $this->caisseHandler = $this->createMock(HandleCaisseTransactionUseCase::class);
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);

        $this->emProvider->method('getEntityManager')->willReturn($this->entityManager);
        $this->entityManager->method('getConnection')->willReturn($this->connection);

        $this->useCase = new CancelOrderUseCase(
            $this->paymentHandler,
            $this->stockUpdater,
            $this->caisseHandler,
            $this->emProvider
        );
    }

    public function testExecuteOrderNotFound(): void
    {
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('rollback');

        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('find')->with(1)->willReturn(null);
        $this->entityManager->method('getRepository')->with(Order::class)->willReturn($repo);

        $response = $this->useCase->execute(1, 1);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertStringContainsString('Order not found', $response->getContent());
    }

    public function testExecuteOrderAlreadyShipped(): void
    {
        $order = $this->createMock(Order::class);
        $status = $this->createMock(StatusCommande::class);
        $status->method('getId')->willReturn(5); // Shipped
        $order->method('getStatus')->willReturn($status);

        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('find')->with(1)->willReturn($order);
        $this->entityManager->method('getRepository')->with(Order::class)->willReturn($repo);

        $this->connection->expects($this->once())->method('rollback');

        $response = $this->useCase->execute(1, 1);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertStringContainsString('cannot be canceled', $response->getContent());
    }

    public function testExecuteSuccess(): void
    {
        // 1. Setup Order logic
        $order = $this->createMock(Order::class);
        $order->method('getTotalAmount')->willReturn(100.0);
        $order->method('getStatus')->willReturn($this->createMock(StatusCommande::class)); // Some valid status
        $order->method('getOrderSource')->willReturn($this->createMock(OrderSource::class)); // Not POS

        // 2. Setup Status Repo
        $cancelStatus = $this->createMock(StatusCommande::class);

        $orderRepo = $this->createMock(ObjectRepository::class);
        $orderRepo->method('find')->willReturn($order);

        $statusRepo = $this->createMock(ObjectRepository::class);
        $statusRepo->method('find')->with(7)->willReturn($cancelStatus);

        $this->entityManager->method('getRepository')->willReturnMap([
            [Order::class, $orderRepo],
            [StatusCommande::class, $statusRepo]
        ]);

        // 3. Setup Items for Stock Update
        $variant = $this->createMock(ProductVariant::class);
        $item = $this->createMock(OrderItems::class);
        $item->method('getProductVariant')->willReturn($variant);
        $item->method('getQuantity')->willReturn(2);

        $order->method('getOrderItems')->willReturn(new ArrayCollection([$item]));

        // 4. Setup Taxes
        $tax = $this->createMock(OrderTax::class);
        $tax->expects($this->once())->method('setAmount')->with(0);

        $order->method('getOrderTaxes')->willReturn(new ArrayCollection([$tax]));

        // Expectations
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('commit');
        $this->entityManager->expects($this->once())->method('flush');

        $order->expects($this->once())->method('setStatus')->with($cancelStatus);
        $order->expects($this->once())->method('setTotalAmount')->with(0);

        $this->paymentHandler->expects($this->once())
            ->method('handlePayment')
            ->with($order, -100.0); // Refund

        $this->stockUpdater->expects($this->once())
            ->method('execute')
            ->with($variant, 2, true); // true = restoration

        $response = $this->useCase->execute(1, 1);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testExecuteSuccessPosOrder(): void
    {
        // 1. Setup Order logic
        $order = $this->createMock(Order::class);
        $user = $this->createMock(User::class);
        $order->method('getUserId')->willReturn($user);
        $order->method('getTotalAmount')->willReturn(50.0);
        $order->method('getStatus')->willReturn($this->createMock(StatusCommande::class));

        $posSource = $this->createMock(OrderSource::class);
        $posSource->method('getId')->willReturn(2); // POS
        $order->method('getOrderSource')->willReturn($posSource);

        // 2. Setup Status Repo
        $cancelStatus = $this->createMock(StatusCommande::class);

        $orderRepo = $this->createMock(ObjectRepository::class);
        $orderRepo->method('find')->willReturn($order);

        $statusRepo = $this->createMock(ObjectRepository::class);
        $statusRepo->method('find')->with(7)->willReturn($cancelStatus);

        $this->entityManager->method('getRepository')->willReturnMap([
            [Order::class, $orderRepo],
            [StatusCommande::class, $statusRepo]
        ]);

        // Trigger POS logic
        $this->caisseHandler->expects($this->once())
            ->method('execute')
            ->with($order, $user, 50.0, 2);

        $response = $this->useCase->execute(1, 1); // PaymentMethod 1 (Card/Check/etc)

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }
}
