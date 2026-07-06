<?php

namespace App\Services\GemsuiteImporterService;

use App\Entity\Booking;
use App\Entity\BookingConfiguration;
use App\Entity\Product;
use App\Entity\Vehicle;
use App\Entity\VehicleTranslation;
use App\Enum\ProductMode;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\TenantEntityManagerProvider;
use App\Services\GemsuiteImporterService\GemsuiteStockCalculator;
use App\Entity\RentalPack;
use Psr\Log\LoggerInterface;
use App\Services\TenantCacheService;

class GemsuiteRentalWorkaroundService

{
    public function __construct(
        private TenantEntityManagerProvider $emProvider,
        private GemsuiteStockCalculator $stockCalculator,
        private LoggerInterface $logger,
        private GemsuiteImageUrlBuilder $imageUrlBuilder,
        private TenantCacheService $cache
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

        // Fetch company identifier once for resolving images
        $entreprise = $this->getEm()->getRepository(\App\Entity\Entreprise::class)->findOneBy([]);
        $companyIdentifier = $entreprise ? $entreprise->getGemsuiteIdentifier() : null;

        foreach ($vehiclesData as $vData) {
            $gemsuiteVehicleId = $vData['id'] ?? null;
            $gemsuiteProductId = $vData['product_id'] ?? null;

            if (!$gemsuiteVehicleId) {
                continue;
            }

            $vehicle = $vehicleRepo->findOneBy(['gemsuiteVehicleId' => $gemsuiteVehicleId]);

            $product = null;
            if ($gemsuiteProductId && (int)$gemsuiteProductId !== 0) {
                $product = $productRepo->findOneBy(['gemsuiteProductId' => $gemsuiteProductId]);
            }

            // Création ou Mise à jour
            if (!$vehicle) {
                $vehicle = new Vehicle();
                $vehicle->setGemsuiteVehicleId($gemsuiteVehicleId);
                $this->logger->info("[SyncVehicles] Création du véhicule Gemsuite #$gemsuiteVehicleId.");
            }

            $vehicle->setProduct($product);

            // Populate scalar fields
            $vehicle->setTitle($vData['web_title_fr'] ?? $vData['web_title'] ?? $vData['seo_title'] ?? null);
            $vehicle->setDescription($vData['web_description_fr'] ?? $vData['web_description'] ?? null);
            $vehicle->setYear($vData['year'] ?? null);
            $vehicle->setColor($vData['color'] ?? null);
            $vehicle->setTransmission($vData['transmission'] ?? null);
            $vehicle->setGasType($vData['gas_type'] ?? null);
            $vehicle->setNewVehicle(isset($vData['new_vehicle']) ? (bool)$vData['new_vehicle'] : null);
            $vehicle->setFeaturedVehicle(isset($vData['featured_vehicle']) ? (bool)$vData['featured_vehicle'] : null);
            $vehicle->setWebDisplay(isset($vData['web_display']) ? (bool)$vData['web_display'] : null);
            $vehicle->setSlug($vData['web_slug'] ?? null);

            // Persist translations (FR and EN)
            $translationData = [
                'fr' => [
                    'title'       => $vData['web_title_fr'] ?? $vData['web_title'] ?? $vData['seo_title'] ?? null,
                    'description' => $vData['web_description_fr'] ?? null,
                ],
                'en' => [
                    'title'       => $vData['web_title'] ?? $vData['web_title_fr'] ?? $vData['seo_title'] ?? null,
                    'description' => $vData['web_description'] ?? $vData['web_description_fr'] ?? null,
                ],
            ];

            foreach ($translationData as $lang => $fields) {
                /** @var VehicleTranslation|null $existingTranslation */
                $existingTranslation = $vehicle->findTranslationByLocale($lang);

                if (!$existingTranslation) {
                    $existingTranslation = new VehicleTranslation();
                    $existingTranslation->setLanguage($lang);
                    $vehicle->addTranslation($existingTranslation);
                }

                $existingTranslation->setTitle($fields['title']);
                $existingTranslation->setDescription($fields['description']);
            }

            // Resolve main picture
            $pictureUrl = null;
            $medias = $vData['media'] ?? [];
            if (!empty($medias)) {
                $mainMedia = null;
                foreach ($medias as $media) {
                    $mediaTypeId = $media['media_type_id'] ?? null;
                    $nom = $media['nom'] ?? '';
                    if ((int)$mediaTypeId === 1 || strtolower($nom) === 'principale') {
                        $mainMedia = $media;
                        break;
                    }
                }
                if (!$mainMedia && !empty($medias)) {
                    $mainMedia = $medias[0];
                }
                if ($mainMedia && isset($mainMedia['path']) && $companyIdentifier) {
                    $pictureUrl = $this->imageUrlBuilder->buildUrl($companyIdentifier, $mainMedia['path']);
                }
            }
            $vehicle->setPicture($pictureUrl);

            $this->getEm()->persist($vehicle);
        }

        $this->getEm()->flush();
        $this->cache->delete('vehicles_carousel');
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

        // 1. On récupère tous les bookings existants de ce produit pour éviter les doublons avec le front
        /** @var Booking[] $existingBookings */
        $existingBookings = $bookingRepo->findBy([
            'product' => $product,
        ]);

        // On prépare un dictionnaire pour faciliter le matching par dates
        $tz = new \DateTimeZone('America/Toronto');
        $existingByDates = [];
        foreach ($existingBookings as $eb) {
            $startLocal = $eb->getStartAt() ? (clone $eb->getStartAt())->setTimezone($tz) : null;
            $endLocal = $eb->getEndAt() ? (clone $eb->getEndAt())->setTimezone($tz) : null;
            $key = ($startLocal ? $startLocal->format('Y-m-d H:i') : '') . '|' . ($endLocal ? $endLocal->format('Y-m-d H:i') : '');
            $existingByDates[$key][] = $eb;
        }

        $touchedIds = [];

        // 2. Traitement des nouveaux rendez-vous renvoyés par l'API
        foreach ($appointments as $appt) {
            if (empty($appt['start']) || empty($appt['end'])) {
                continue;
            }

            $startAt = new \DateTimeImmutable($appt['start'], $tz);
            $endAt = new \DateTimeImmutable($appt['end'], $tz);
            $key = $startAt->format('Y-m-d H:i') . '|' . $endAt->format('Y-m-d H:i');

            if (isset($existingByDates[$key]) && !empty($existingByDates[$key])) {
                // On a déjà un booking local avec ces dates exactes, on le réutilise
                $booking = array_shift($existingByDates[$key]);
                $touchedIds[] = $booking->getId();
            } else {
                // Nouveau rendez-vous (venant du calendrier général, sans sale_id forcément connu ici)
                $booking = new Booking();
                $booking->setProduct($product);
                $booking->setStartAt($startAt->setTimezone(new \DateTimeZone('UTC')));
                $booking->setEndAt($endAt->setTimezone(new \DateTimeZone('UTC')));
                $booking->setQuantity(1);
                $booking->setStatus('gemsuite_sync');
                $this->getEm()->persist($booking);
                // On ne flush pas encore, on flush à la fin
            }
        }

        // 3. Purge des anciens bookings qui n'étaient plus dans la liste renvoyée par Gemsuite
        // ATTENTION : On ne supprime que ceux qui n'ont pas été "touched" 
        // ET qui sont de type "gemsuite_sync" (les réservations web en cours/payées restent préservées)
        foreach ($existingByDates as $unusedList) {
            foreach ($unusedList as $unusedBooking) {
                if ($unusedBooking->getStatus() === 'gemsuite_sync') {
                    $this->getEm()->remove($unusedBooking);
                }
            }
        }

        $this->getEm()->flush();
    }
}
