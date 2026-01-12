<?php

namespace App\Tests\Unit\UseCase\Booking;

use App\Entity\Product;
use App\Services\Booking\BookingAvailabilityService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\Booking\GetCalendarAvailabilityUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GetCalendarAvailabilityUseCaseTest extends TestCase
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

        $this->useCase = new GetCalendarAvailabilityUseCase(
            $this->availabilityService,
            $this->tenantEmProvider
        );
    }

    public function testExecuteProductNotFound(): void
    {
        $this->repository->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $start = new \DateTime('2024-01-01');
        $end = new \DateTime('2024-01-31');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Produit introuvable.');

        $this->useCase->execute(999, $start, $end);
    }

    public function testExecuteProductNotBookable(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('isBookable')->willReturn(false);

        $this->repository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($product);

        $start = new \DateTime('2024-01-01');
        $end = new \DateTime('2024-01-31');

        $result = $this->useCase->execute(1, $start, $end);

        $this->assertSame([], $result);
    }

    public function testExecuteSuccess(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('isBookable')->willReturn(true);

        $this->repository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($product);

        $start = new \DateTime('2024-01-01');
        $end = new \DateTime('2024-01-31');
        $expected = ['day1' => 'full', 'day2' => 'free'];

        $this->availabilityService->expects($this->once())
            ->method('getAvailabilitiesForRange')
            ->with($product, $start, $end)
            ->willReturn($expected);

        $result = $this->useCase->execute(1, $start, $end);

        $this->assertSame($expected, $result);
    }
}
