<?php

namespace App\Tests\Unit\Services\OrderService;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Services\OrderService\OrderService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

class OrderServiceTest extends TestCase
{
    private $emProvider;
    private $entityManager;
    private $orderRepository;
    private $orderService;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->orderRepository = $this->createMock(OrderRepository::class);

        $this->emProvider->method('getEntityManager')->willReturn($this->entityManager);
        $this->entityManager->method('getRepository')->with(Order::class)->willReturn($this->orderRepository);

        $this->orderService = new OrderService($this->emProvider);
    }

    public function testGetOrdersByOrderSourceWithDays(): void
    {
        $orderSourceId = 1;
        $days = 30;
        $expectedResult = [];

        $this->orderRepository->expects($this->once())
            ->method('findWithDetailsBySource')
            ->with($orderSourceId, $this->isInstanceOf(\DateTime::class))
            ->willReturn($expectedResult);

        $result = $this->orderService->getOrdersByOrderSource($orderSourceId, $days);

        $this->assertSame($expectedResult, $result);
    }

    public function testGetOrdersByOrderSourceWithoutDays(): void
    {
        $orderSourceId = 1;
        $expectedResult = [];

        $this->orderRepository->expects($this->once())
            ->method('findWithDetailsBySource')
            ->with($orderSourceId, null)
            ->willReturn($expectedResult);

        $result = $this->orderService->getOrdersByOrderSource($orderSourceId);

        $this->assertSame($expectedResult, $result);
    }

    public function testGetOrdersByUser(): void
    {
        $userId = 123;
        $expectedResult = [];

        $this->orderRepository->expects($this->once())
            ->method('findWithDetailsByUser')
            ->with($userId)
            ->willReturn($expectedResult);

        $result = $this->orderService->getOrdersByUser($userId);

        $this->assertSame($expectedResult, $result);
    }
}
