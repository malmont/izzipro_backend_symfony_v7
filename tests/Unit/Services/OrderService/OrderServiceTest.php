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

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);
        $expectedResult = [];

        $this->orderRepository->expects($this->once())
            ->method('createQueryBuilder')
            ->with('o')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('where')
            ->with('o.orderSource = :orderSourceId')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('o.orderDate >= :date')
            ->willReturnSelf();

        $queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->withConsecutive(
                ['orderSourceId', $orderSourceId],
                ['date', $this->isInstanceOf(\DateTime::class)]
            )
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);

        $query->expects($this->once())
            ->method('getResult')
            ->willReturn($expectedResult);

        $result = $this->orderService->getOrdersByOrderSource($orderSourceId, $days);

        $this->assertSame($expectedResult, $result);
    }

    public function testGetOrdersByOrderSourceWithoutDays(): void
    {
        $orderSourceId = 1;
        $expectedResult = [];

        $this->orderRepository->expects($this->once())
            ->method('findBy')
            ->with(['orderSource' => $orderSourceId])
            ->willReturn($expectedResult);

        $result = $this->orderService->getOrdersByOrderSource($orderSourceId);

        $this->assertSame($expectedResult, $result);
    }

    public function testGetOrdersByUser(): void
    {
        $userId = 123;
        $expectedResult = [];

        $this->orderRepository->expects($this->once())
            ->method('findBy')
            ->with(['userId' => $userId])
            ->willReturn($expectedResult);

        $result = $this->orderService->getOrdersByUser($userId);

        $this->assertSame($expectedResult, $result);
    }
}
