<?php

namespace App\UseCase\Booking;

use App\Dto\BookingSetupRequest;
use App\Entity\BookingConfiguration;
use App\Entity\Product;
use App\Enum\ProductMode;
use App\Services\TenantEntityManagerProvider; // <--- Import du Provider

class UpdateBookingConfiguration
{
    // MODIFICATION 1 : On injecte ton Provider au lieu de l'EntityManager direct
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function execute(Product $product, BookingSetupRequest $dto): void
    {
        // MODIFICATION 2 : On récupère l'EntityManager du locataire actif
        $em = $this->emProvider->getEntityManager();

        // 1. Récupérer ou créer la configuration
        $config = $product->getBookingConfiguration();

        if (!$config) {
            $config = new BookingConfiguration();
            $config->setProduct($product);
        }

        // 2. Transférer les données du DTO vers l'Entité Config
        $config->setGranularity($dto->granularity);
        $config->setStockQuantity($dto->stockQuantity);
        $config->setMinDuration($dto->minDuration);
        $config->setBufferTime($dto->bufferTime ?? 30);

        // 3. Appliquer la logique métier "Product Mode"
        if ($dto->enableBooking) {
            $product->setMode(ProductMode::BOOKING);
        } else {
            $product->setMode(ProductMode::RETAIL);
        }

        // 4. Persistance sur la bonne base de données
        $em->persist($config);
        $em->persist($product);

        $em->flush();
    }
}
