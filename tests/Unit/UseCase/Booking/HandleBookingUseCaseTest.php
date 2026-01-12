<?php

namespace App\Tests\Unit\UseCase\Booking;

use App\Entity\Booking;
use App\Entity\Order;
use App\Entity\OrderItems;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\Booking\BookingAvailabilityService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\Booking\HandleBookingUseCase;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class HandleBookingUseCaseTest extends TestCase
{
    private $bookingAvailabilityService;
    private $emProvider;
    private $entityManager;
    private $variantRepo;
    private $useCase;

    protected function setUp(): void
    {
        $this->bookingAvailabilityService = $this->createMock(BookingAvailabilityService::class);
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->variantRepo = $this->createMock(ObjectRepository::class);

        $this->emProvider->method('getEntityManager')->willReturn($this->entityManager);
        $this->entityManager->method('getRepository')->with(ProductVariant::class)->willReturn($this->variantRepo);

        $this->useCase = new HandleBookingUseCase(
            $this->bookingAvailabilityService,
            $this->emProvider
        );
    }

    public function testExecuteIgnoresNonBookableOrMissingVariants(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getOrderItems')->willReturn(new ArrayCollection());
        $order->method('getId')->willReturn(1); // To trigger refresh

        $itemsArray = [
            ['productVariantId' => 999] // Exists but product not bookable
        ];

        $variant = $this->createMock(ProductVariant::class);
        $product = $this->createMock(Product::class);
        $product->method('isBookable')->willReturn(false); // Not bookable
        $variant->method('getProduct')->willReturn($product);

        $this->variantRepo->method('find')->with(999)->willReturn($variant);

        $this->entityManager->expects($this->never())->method('persist');

        $this->useCase->execute($order, $itemsArray);
    }

    public function testExecuteThrowsExceptionMissingDates(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getOrderItems')->willReturn(new ArrayCollection());

        $itemsArray = [
            ['productVariantId' => 123, 'booking' => []] // Missing start/end
        ];

        $variant = $this->createMock(ProductVariant::class);
        $product = $this->createMock(Product::class);
        $product->method('isBookable')->willReturn(true);
        $product->method('getName')->willReturn('My Product');

        $variant->method('getProduct')->willReturn($product);
        $this->variantRepo->method('find')->with(123)->willReturn($variant);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage("Dates manquantes pour le produit 'My Product' (Variante #123).");

        $this->useCase->execute($order, $itemsArray);
    }

    public function testExecuteThrowsExceptionInvalidDates(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getOrderItems')->willReturn(new ArrayCollection());

        $itemsArray = [
            ['productVariantId' => 123, 'booking' => ['start' => 'invalid-date', 'end' => 'ok']]
        ];

        $variant = $this->createMock(ProductVariant::class);
        $product = $this->createMock(Product::class);
        $product->method('isBookable')->willReturn(true);

        $variant->method('getProduct')->willReturn($product);
        $this->variantRepo->method('find')->with(123)->willReturn($variant);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage("Format de date invalide");

        $this->useCase->execute($order, $itemsArray);
    }

    public function testExecuteThrowsExceptionNotAvailable(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getOrderItems')->willReturn(new ArrayCollection());

        $itemsArray = [
            [
                'productVariantId' => 123,
                'quantity' => 2,
                'booking' => ['start' => '2024-01-01 10:00', 'end' => '2024-01-01 12:00']
            ]
        ];

        $variant = $this->createMock(ProductVariant::class);
        $product = $this->createMock(Product::class);
        $product->method('isBookable')->willReturn(true);
        $product->method('getName')->willReturn('Busy Product');

        $variant->method('getProduct')->willReturn($product);
        $this->variantRepo->method('find')->with(123)->willReturn($variant);

        $this->bookingAvailabilityService->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage("Désolé, le créneau pour 'Busy Product' n'est plus disponible.");

        $this->useCase->execute($order, $itemsArray);
    }

    public function testExecuteSuccess(): void
    {
        // 1. Setup Order logic
        $order = $this->createMock(Order::class);

        // Mock existing order items to verify mapping logic
        $itemVariant = $this->createMock(ProductVariant::class);
        $itemVariant->method('getId')->willReturn(123);

        // --- FIX: Ensure the product is bookable! ---
        $itemProduct = $this->createMock(Product::class);
        $itemProduct->method('isBookable')->willReturn(true);
        $itemProduct->method('getName')->willReturn('Bookable Product');

        $itemVariant->method('getProduct')->willReturn($itemProduct);
        // --- END FIX ---

        $orderItem = $this->createMock(OrderItems::class);
        $orderItem->method('getProductVariant')->willReturn($itemVariant);
        // We expect setBooking to be called on this item
        $orderItem->expects($this->once())->method('setBooking')->with($this->isInstanceOf(Booking::class));

        $order->method('getOrderItems')->willReturn(new ArrayCollection([$orderItem]));

        // 2. Setup Input Data
        $itemsArray = [
            [
                'productVariantId' => 123,
                'quantity' => 1,
                'booking' => ['start' => '2024-01-01 10:00', 'end' => '2024-01-01 12:00']
            ]
        ];

        // 3. Mock Repositories
        // Note: The code prioritizes finding variant via $orderItemsMap if available.
        // If logic is correct, it WON'T call repo->find(123) because 123 is in the map.
        // Let's verify that.
        $this->variantRepo->expects($this->never())->method('find');

        // 4. Mock Availability
        $this->bookingAvailabilityService->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        // 5. Mock Persistence
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(Booking::class));

        $this->useCase->execute($order, $itemsArray);
    }
}
