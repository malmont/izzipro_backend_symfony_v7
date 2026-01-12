<?php

namespace App\Tests\Unit\UseCase\Booking;

use App\Dto\AvailabilityCheckDto;
use App\Entity\Product;
use App\Services\Booking\BookingAvailabilityService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\Booking\CheckAvailabilityUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CheckAvailabilityUseCaseTest extends TestCase
{
    private $availabilityService;
    private $tenantEmProvider;
    private $entityManager;
    private $repository;
    private $useCase;

    protected function setUp(): void
    {
        $this->availabilityService = $this->createMock(BookingAvailabilityService::class);
        $this->tenantEmProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(ObjectRepository::class);

        $this->tenantEmProvider->method('getEntityManager')->willReturn($this->entityManager);
        $this->entityManager->method('getRepository')->with(Product::class)->willReturn($this->repository);

        $this->useCase = new CheckAvailabilityUseCase(
            $this->availabilityService,
            $this->tenantEmProvider
        );
    }

    public function testExecuteProductNotFound(): void
    {
        $dto = new AvailabilityCheckDto();
        $dto->productId = 999;

        $this->repository->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Produit introuvable.');

        $this->useCase->execute($dto);
    }

    public function testExecuteAvailable(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getName')->willReturn('Produit Test');

        $dto = new AvailabilityCheckDto();
        $dto->productId = 1;
        $dto->startAt = new \DateTime('2024-01-01 10:00');
        $dto->endAt = new \DateTime('2024-01-01 11:00');
        $dto->quantity = 2;

        $this->repository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($product);

        $this->availabilityService->expects($this->once())
            ->method('getRemainingStock')
            ->with($product, $dto->startAt, $dto->endAt)
            ->willReturn(5);

        $result = $this->useCase->execute($dto);

        $this->assertTrue($result['available']);
        $this->assertEquals(5, $result['remaining_stock']);
        $this->assertEquals(2, $result['quantity_requested']);
        $this->assertEquals('Produit Test', $result['product_name']);
    }

    public function testExecuteNotAvailable(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getName')->willReturn('Produit Test');

        $dto = new AvailabilityCheckDto();
        $dto->productId = 1;
        $dto->startAt = new \DateTime('2024-01-01 10:00');
        $dto->endAt = new \DateTime('2024-01-01 11:00');
        $dto->quantity = 2;

        $this->repository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($product);

        $this->availabilityService->expects($this->once())
            ->method('getRemainingStock')
            ->with($product, $dto->startAt, $dto->endAt)
            ->willReturn(1); // Only 1 left, requested 2

        $result = $this->useCase->execute($dto);

        $this->assertFalse($result['available']);
        $this->assertEquals(1, $result['remaining_stock']);
        $this->assertEquals(2, $result['quantity_requested']);
    }
}
