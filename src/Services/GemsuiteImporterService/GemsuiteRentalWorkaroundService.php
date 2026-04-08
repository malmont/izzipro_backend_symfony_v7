<?php

namespace App\Services\GemsuiteImporterService;

use App\Entity\Booking;
use App\Entity\BookingConfiguration;
use App\Entity\Product;
use App\Entity\Vehicle;
use App\Enum\ProductMode;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\TenantEntityManagerProvider;
use App\Services\GemsuiteImporterService\GemsuiteStockCalculator;

class GemsuiteRentalWorkaroundService
{
    public function __construct(
        private TenantEntityManagerProvider $emProvider,
        private GemsuiteStockCalculator $stockCalculator
    ) {}

    private function getEm(): EntityManagerInterface
    {
        return $this->emProvider->getEntityManager();
    }

    /**
     * TODO: TEMP WORKAROUND - Configured rental product fields
     */
    public function applyRentalProductConfiguration(Product $product, array $data): void
    {
        $product->setMode(ProductMode::BOOKING);

        $bookingConfig = $product->getBookingConfiguration();
        if (!$bookingConfig) {
            $bookingConfig = new BookingConfiguration();
            $bookingConfig->setProduct($product);
        }

        $realStock = $this->stockCalculator->calculateTotalStock($data);

        $bookingConfig->setGranularity('hours');
        $bookingConfig->setStockQuantity($realStock); // Uses calculated stock
        $bookingConfig->setMinDuration(1); // Minimum 1 hour
        $bookingConfig->setBufferTime(0);

        $product->setBookingConfiguration($bookingConfig);
    }

    /**
     * TODO: TEMP WORKAROUND - Link vehicles to products locally
     */
    public function syncVehicles(array $vehiclesData): void
    {
        $vehicleRepo = $this->getEm()->getRepository(Vehicle::class);
        $productRepo = $this->getEm()->getRepository(Product::class);

        foreach ($vehiclesData as $vData) {
            $gemsuiteVehicleId = $vData['id'] ?? null;
            $gemsuiteProductId = $vData['product_id'] ?? null;

            if (!$gemsuiteVehicleId || !$gemsuiteProductId) {
                continue;
            }

            $product = $productRepo->findOneBy(['gemsuiteProductId' => $gemsuiteProductId]);
            if (!$product) {
                continue; // Product does not exist locally yet
            }

            $vehicle = $vehicleRepo->findOneBy(['gemsuiteVehicleId' => $gemsuiteVehicleId]);
            if (!$vehicle) {
                $vehicle = new Vehicle();
                $vehicle->setGemsuiteVehicleId($gemsuiteVehicleId);
            }

            $vehicle->setProduct($product);
            $this->getEm()->persist($vehicle);
        }

        $this->getEm()->flush();
    }

    /**
     * TODO: TEMP WORKAROUND - Sync appointments
     */
    public function syncRentalsForVehicle(int $gemsuiteVehicleId, array $appointments): void
    {
        $vehicleRepo = $this->getEm()->getRepository(Vehicle::class);
        $vehicle = $vehicleRepo->findOneBy(['gemsuiteVehicleId' => $gemsuiteVehicleId]);

        if (!$vehicle || !$vehicle->getProduct()) {
            return;
        }

        $product = $vehicle->getProduct();

        // 1. Purge ancient bookings (gemsuite_sync)
        $bookingRepo = $this->getEm()->getRepository(Booking::class);
        $oldBookings = $bookingRepo->findBy([
            'product' => $product,
            'status' => 'gemsuite_sync'
        ]);

        foreach ($oldBookings as $oldBooking) {
            $this->getEm()->remove($oldBooking);
        }
        $this->getEm()->flush();

        // 2. Insert new appointments
        foreach ($appointments as $appt) {
            if (empty($appt['start']) || empty($appt['end'])) {
                continue;
            }

            $startAt = new \DateTimeImmutable($appt['start']);
            $endAt = new \DateTimeImmutable($appt['end']);

            $booking = new Booking();
            $booking->setProduct($product);
            $booking->setStartAt($startAt);
            $booking->setEndAt($endAt);
            $booking->setQuantity(1);
            $booking->setStatus('gemsuite_sync');

            $this->getEm()->persist($booking);
        }

        $this->getEm()->flush();
    }
}
