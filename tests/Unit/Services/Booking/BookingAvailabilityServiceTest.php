<?php

namespace App\Tests\Unit\Services\Booking;

use App\Entity\Booking;
use App\Entity\BookingConfiguration;
use App\Entity\Product;
use App\Enum\ProductMode;
use App\Services\Booking\BookingAvailabilityService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class BookingAvailabilityServiceTest extends TestCase
{
    private $emProvider;
    private $entityManager;
    private $bookingRepo;
    private $service;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->bookingRepo = $this->createMock(MockBookingRepository::class);

        $this->entityManager->method('getRepository')
            ->with(Booking::class)
            ->willReturn($this->bookingRepo);

        $this->emProvider->method('getEntityManager')
            ->willReturn($this->entityManager);

        $this->service = new BookingAvailabilityService($this->emProvider);
    }

    public function testGetRemainingStockNonBookingProduct(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getMode')->willReturn(ProductMode::RETAIL);
        $product->method('getQuantity')->willReturn(10);

        $remaining = $this->service->getRemainingStock($product, new \DateTime(), new \DateTime());
        $this->assertEquals(10, $remaining);
    }

    public function testGetRemainingStockNoConfig(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getMode')->willReturn(ProductMode::BOOKING);
        $product->method('getBookingConfiguration')->willReturn(null);

        $remaining = $this->service->getRemainingStock($product, new \DateTime(), new \DateTime());
        $this->assertEquals(0, $remaining);
    }

    public function testGetRemainingStockCalculatesCorrectly(): void
    {
        $config = $this->createMock(BookingConfiguration::class);
        $config->method('getStockQuantity')->willReturn(50); // Total capacity

        $product = $this->createMock(Product::class);
        $product->method('getMode')->willReturn(ProductMode::BOOKING);
        $product->method('getBookingConfiguration')->willReturn($config);

        // Repo reports 10 reserved
        $this->bookingRepo->expects($this->once())
            ->method('countReservedQuantityBetween')
            ->willReturn(10);

        $remaining = $this->service->getRemainingStock($product, new \DateTime(), new \DateTime());
        $this->assertEquals(40, $remaining); // 50 - 10
    }

    public function testIsAvailableReturnsTrueIfStockSufficient(): void
    {
        // Mocking via partial mock of service to avoid re-mocking all dependencies for getRemainingStock behavior
        // Or better: Just mock the dependencies like specific test case above

        $config = $this->createMock(BookingConfiguration::class);
        $config->method('getStockQuantity')->willReturn(5);

        $product = $this->createMock(Product::class);
        $product->method('getMode')->willReturn(ProductMode::BOOKING);
        $product->method('getBookingConfiguration')->willReturn($config);

        $this->bookingRepo->method('countReservedQuantityBetween')->willReturn(2); // 5 - 2 = 3 remaining

        $this->assertTrue($this->service->isAvailable($product, new \DateTime(), new \DateTime(), 3));
        $this->assertFalse($this->service->isAvailable($product, new \DateTime(), new \DateTime(), 4));
    }

    public function testGetAvailabilitiesForRange(): void
    {
        $start = new \DateTimeImmutable('2023-01-01 10:00:00');
        $end = new \DateTimeImmutable('2023-01-01 12:00:00'); // 2 hours range

        $config = $this->createMock(BookingConfiguration::class);
        $config->method('getStockQuantity')->willReturn(5);
        $config->method('getGranularity')->willReturn('hours');

        $product = $this->createMock(Product::class);
        $product->method('getBookingConfiguration')->willReturn($config);

        // Booking 1: 10:00 - 11:00, Qty 2
        $booking1 = $this->createMock(Booking::class);
        $booking1->method('getStartAt')->willReturn(new \DateTimeImmutable('2023-01-01 10:00:00'));
        $booking1->method('getEndAt')->willReturn(new \DateTimeImmutable('2023-01-01 11:00:00'));
        $booking1->method('getQuantity')->willReturn(2);

        $this->bookingRepo->expects($this->once())
            ->method('findBookingsOverlapping')
            ->with($product, $start, $end)
            ->willReturn([$booking1]);

        $results = $this->service->getAvailabilitiesForRange($product, $start, $end);

        // Should have 2 slots: 10-11 and 11-12
        $this->assertCount(2, $results);

        // Slot 1: 10-11. Total 5, Booking 2. Remaining 3.
        $this->assertEquals('2023-01-01 10:00:00', $results[0]['start']);
        $this->assertEquals(3, $results[0]['remaining']);
        $this->assertEquals('available', $results[0]['status']);

        // Slot 2: 11-12. Total 5, Booking 0 (no overlap). Remaining 5.
        $this->assertEquals('2023-01-01 11:00:00', $results[1]['start']);
        $this->assertEquals(5, $results[1]['remaining']);
        $this->assertEquals('available', $results[1]['status']);
    }
}

interface MockBookingRepository extends \Doctrine\Persistence\ObjectRepository
{
    public function countReservedQuantityBetween($product, $start, $end);
    public function findBookingsOverlapping($product, $start, $end);
}
