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
use App\Entity\RentalPack;

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

        // Initial default, will be refined by updateSmartGranularity
        $bookingConfig->setGranularity('hours');
        $bookingConfig->setStockQuantity($realStock);
        $bookingConfig->setMinDuration(1); 
        $bookingConfig->setBufferTime(0);

        $product->setBookingConfiguration($bookingConfig);
        
        // Refine granularity based on packs
        $this->updateSmartGranularity($product);
    }

    /**
     * Update granularity based on associated RentalPacks
     */
    public function updateSmartGranularity(Product $product): void
    {
        $categories = $product->getCategory();
        $hasHourly = false;

        foreach ($categories as $category) {
            foreach ($category->getRentalPacks() as $pack) {
                if (($pack->getHourRate() ?? 0) > 0 || ($pack->getHalfDayRate() ?? 0) > 0) {
                    $hasHourly = true;
                    break 2;
                }
            }
        }

        $bookingConfig = $product->getBookingConfiguration();
        if ($bookingConfig) {
            $bookingConfig->setGranularity($hasHourly ? 'hours' : 'days');
            // Ensure mode is correct
            $product->setMode(ProductMode::BOOKING);
        }
    }

    /**
     * Remove rental configuration correctly
     */
    public function removeRentalConfiguration(Product $product, EntityManagerInterface $em): void
    {
        $product->setMode(ProductMode::RETAIL);
        $bookingConfig = $product->getBookingConfiguration();
        
        if ($bookingConfig) {
            $em->remove($bookingConfig);
            $product->setBookingConfiguration(null);
        }
    }

    /**
     * Update granularity via bulk DQL to avoid timeout
     */
    public function updateGranularityBulk(array $categories, RentalPack $pack, EntityManagerInterface $em): void
    {
        if (empty($categories)) {
            return;
        }

        $hasHourly = ($pack->getHourRate() ?? 0) > 0 || ($pack->getHalfDayRate() ?? 0) > 0;
        $granularity = $hasHourly ? 'hours' : 'days';

        $categoryIds = array_map(fn($c) => $c->getId(), $categories);

        $qbSub = $em->createQueryBuilder()
            ->select('p_sub.id')
            ->from(Product::class, 'p_sub')
            ->join('p_sub.category', 'c_sub')
            ->where('c_sub.id IN (:categoryIds)')
            ->getDQL();

        $qb = $em->createQueryBuilder();
        $qb->update(BookingConfiguration::class, 'bc')
           ->set('bc.granularity', ':granularity')
           ->where($qb->expr()->in('bc.product', $qbSub))
           ->setParameter('granularity', $granularity)
           ->setParameter('categoryIds', $categoryIds);

        $qb->getQuery()->execute();
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
