<?php

namespace App\Tests\Unit\UseCase\Booking;

use App\Dto\BookingSetupRequest;
use App\Entity\BookingConfiguration;
use App\Entity\Product;
use App\Enum\ProductMode;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\Booking\UpdateBookingConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class UpdateBookingConfigurationTest extends TestCase
{
    private $emProvider;
    private $entityManager;
    private $useCase;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->emProvider->method('getEntityManager')->willReturn($this->entityManager);

        $this->useCase = new UpdateBookingConfiguration($this->emProvider);
    }

    public function testExecuteCreatesConfigIfMissing(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getBookingConfiguration')->willReturn(null);

        $request = new BookingSetupRequest();
        $request->granularity = 'minutes_60';
        $request->stockQuantity = 50;
        $request->enableBooking = true;

        // Expect setBookingConfiguration might not be called by the use case (it does setProduct on the config side)
        // But we expect persist calls.
        $this->entityManager->expects($this->exactly(2))->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        // Expect Product Mode change
        $product->expects($this->once())
            ->method('setMode')
            ->with(ProductMode::BOOKING);

        $this->useCase->execute($product, $request);
    }

    public function testExecuteUpdatesExistingConfig(): void
    {
        $config = $this->createMock(BookingConfiguration::class);

        $product = $this->createMock(Product::class);
        $product->method('getBookingConfiguration')->willReturn($config);

        $request = new BookingSetupRequest();
        $request->granularity = 'day_1';
        $request->stockQuantity = 10;
        $request->minDuration = 5;
        $request->bufferTime = 15;
        $request->enableBooking = true;

        $config->expects($this->once())->method('setGranularity')->with('day_1');
        $config->expects($this->once())->method('setStockQuantity')->with(10);
        $config->expects($this->once())->method('setMinDuration')->with(5);
        $config->expects($this->once())->method('setBufferTime')->with(15);

        $product->expects($this->once())->method('setMode')->with(ProductMode::BOOKING);

        $this->entityManager->expects($this->exactly(2))->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $this->useCase->execute($product, $request);
    }

    public function testExecuteDisablesBookingMode(): void
    {
        $config = $this->createMock(BookingConfiguration::class);
        $product = $this->createMock(Product::class);
        $product->method('getBookingConfiguration')->willReturn($config);

        $request = new BookingSetupRequest();
        $request->enableBooking = false;

        $product->expects($this->once())
            ->method('setMode')
            ->with(ProductMode::RETAIL);

        $this->useCase->execute($product, $request);
    }
}
