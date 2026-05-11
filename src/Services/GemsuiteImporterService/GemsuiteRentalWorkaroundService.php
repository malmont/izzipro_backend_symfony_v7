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
use Psr\Log\LoggerInterface;

class GemsuiteRentalWorkaroundService

{
    public function __construct(
        private TenantEntityManagerProvider $emProvider,
        private GemsuiteStockCalculator $stockCalculator,
        private LoggerInterface $logger
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
        $bookingConfig->setGranularity('minutes_30');
        $bookingConfig->setStockQuantity($realStock);
        $bookingConfig->setMinDuration(1); 
        $bookingConfig->setBufferTime(30);

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
            $bookingConfig->setGranularity($hasHourly ? 'minutes_30' : 'days');
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
        $granularity = $hasHourly ? 'minutes_30' : 'days';

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

            if (!$gemsuiteVehicleId) {
                continue;
            }

            $vehicle = $vehicleRepo->findOneBy(['gemsuiteVehicleId' => $gemsuiteVehicleId]);

            // OPTIMISATION : Si le produit est ID=0 ou non spécifié, on ignore/supprime le véhicule
            if (!$gemsuiteProductId || (int)$gemsuiteProductId === 0) {
                if ($vehicle) {
                    $this->logger->info("[SyncVehicles] Suppression du véhicule Gemsuite #$gemsuiteVehicleId : plus de lien produit (ID=0).");
                    $this->getEm()->remove($vehicle);
                }
                continue;
            }

            $product = $productRepo->findOneBy(['gemsuiteProductId' => $gemsuiteProductId]);
            if (!$product) {
                // Si le produit n'existe pas localement, le véhicule ne sert à rien sur le site
                if ($vehicle) {
                    $this->logger->warning("[SyncVehicles] Suppression du véhicule Gemsuite #$gemsuiteVehicleId : produit local #$gemsuiteProductId non trouvé.");
                    $this->getEm()->remove($vehicle);
                }
                continue;
            }

            // Création ou Mise à jour
            if (!$vehicle) {
                $vehicle = new Vehicle();
                $vehicle->setGemsuiteVehicleId($gemsuiteVehicleId);
                $this->logger->info("[SyncVehicles] Création du véhicule Gemsuite #$gemsuiteVehicleId lié au produit #$gemsuiteProductId.");
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
        $bookingRepo = $this->getEm()->getRepository(Booking::class);

        // 1. On récupère les bookings existants pour ce produit (synchronisés via Gemsuite)
        /** @var Booking[] $existingBookings */
        $existingBookings = $bookingRepo->findBy([
            'product' => $product,
            'status' => 'gemsuite_sync'
        ]);

        // On prépare un dictionnaire pour faciliter le matching par dates
        $existingByDates = [];
        foreach ($existingBookings as $eb) {
            $key = $eb->getStartAt()->format('Y-m-d H:i') . '|' . $eb->getEndAt()->format('Y-m-d H:i');
            $existingByDates[$key][] = $eb;
        }

        $touchedIds = [];

        // 2. Traitement des nouveaux rendez-vous renvoyés par l'API
        foreach ($appointments as $appt) {
            if (empty($appt['start']) || empty($appt['end'])) {
                continue;
            }

            $startAt = new \DateTimeImmutable($appt['start']);
            $endAt = new \DateTimeImmutable($appt['end']);
            $key = $startAt->format('Y-m-d H:i') . '|' . $endAt->format('Y-m-d H:i');

            if (isset($existingByDates[$key]) && !empty($existingByDates[$key])) {
                // On a déjà un booking local avec ces dates exactes, on le réutilise
                $booking = array_shift($existingByDates[$key]);
                $touchedIds[] = $booking->getId();
            } else {
                // Nouveau rendez-vous (venant du calendrier général, sans sale_id forcément connu ici)
                $booking = new Booking();
                $booking->setProduct($product);
                $booking->setStartAt($startAt);
                $booking->setEndAt($endAt);
                $booking->setQuantity(1);
                $booking->setStatus('gemsuite_sync');
                $this->getEm()->persist($booking);
                // On ne flush pas encore, on flush à la fin
            }
        }

        // 3. Purge des anciens bookings qui n'étaient plus dans la liste renvoyée par Gemsuite
        // ATTENTION : On ne supprime que ceux qui n'ont pas été "touched" 
        // ET qui étaient de type "gemsuite_sync" (déjà filtré au début)
        foreach ($existingByDates as $unusedList) {
            foreach ($unusedList as $unusedBooking) {
                $this->getEm()->remove($unusedBooking);
            }
        }

        $this->getEm()->flush();
    }
}
