<?php

namespace App\Tests\Unit\UseCase\OrderUseCase;

use App\Dto\ICreateOrderDTO;
use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\Order;
use App\Entity\OrderSource;
use App\Entity\OrderType;
use App\Entity\StatusCommande;
use App\Entity\User;
use App\Services\EntityRetrieverService;
use App\Services\OrderService\OrderCreationService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\OrderUseCase\CreateOrderCommandUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CreateOrderCommandUseCaseTest extends TestCase
{
    private $emProvider;
    private $entityRetriever;
    private $orderCreationService;
    private $entityManager;
    private $useCase;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->entityRetriever = $this->createMock(EntityRetrieverService::class);
        $this->orderCreationService = $this->createMock(OrderCreationService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->emProvider->method('getEntityManager')->willReturn($this->entityManager);

        $this->useCase = new CreateOrderCommandUseCase(
            $this->emProvider,
            $this->entityRetriever,
            $this->orderCreationService
        );
    }

    public function testExecuteEntityNotFound(): void
    {
        $dto = $this->createMock(ICreateOrderDTO::class);
        $dto->method('getOrderSource')->willReturn(1);

        $this->entityRetriever->expects($this->once())
            ->method('findOrFail')
            ->willThrowException(new NotFoundHttpException('Entity missing'));

        $user = $this->createMock(User::class);

        $result = $this->useCase->execute($dto, $user);

        $this->assertInstanceOf(JsonResponse::class, $result);
        $this->assertEquals(404, $result->getStatusCode());
        $this->assertStringContainsString('Entity missing', $result->getContent());
    }

    public function testExecuteSuccess(): void
    {
        $user = $this->createMock(User::class);
        $dto = $this->createMock(ICreateOrderDTO::class);
        $dto->method('getOrderSource')->willReturn(1);
        $dto->method('getAddressId')->willReturn(2);
        $dto->method('getCarrierId')->willReturn(3);
        $dto->method('getTypeOrder')->willReturn(4);

        $orderSource = $this->createMock(OrderSource::class);
        $address = $this->createMock(Adress::class);
        $carrier = $this->createMock(Carrier::class);
        $status = $this->createMock(StatusCommande::class);
        $orderType = $this->createMock(OrderType::class);

        // Using willReturnCallback or map for multiple findOrFail calls is tricky because arguments differ.
        // We can look at the sequence in the code:
        // 1. OrderSource
        // 2. Adress
        // 3. Carrier (if present)
        // 4. StatusCommande (ID 3)
        // 5. OrderType

        $this->entityRetriever->expects($this->exactly(5))
            ->method('findOrFail')
            ->willReturnOnConsecutiveCalls(
                $orderSource,
                $address,
                $carrier,
                $status,
                $orderType
            );

        $order = $this->createMock(Order::class);

        $this->orderCreationService->expects($this->once())
            ->method('createOrder')
            ->with($user, $orderSource, $address, $carrier, $status, $orderType)
            ->willReturn($order);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($order);

        $result = $this->useCase->execute($dto, $user);

        $this->assertSame($order, $result);
    }
}
