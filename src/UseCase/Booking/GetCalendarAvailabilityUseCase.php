<?php

namespace App\UseCase\Booking;

use App\Entity\Product;
use App\Services\Booking\BookingAvailabilityService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GetCalendarAvailabilityUseCase
{
    public function __construct(
        private BookingAvailabilityService $availabilityService,
        private TenantEntityManagerProvider $emProvider,
        private ?\App\Services\Storefront\PublicProductVisibility $visibility = null, // lot FICHES-HORS-LIGNE (21/11/2026)
    ) {}

    public function execute(int $productId, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $product = $tenantEm->getRepository(Product::class)->find($productId);

        if (!$product || $this->visibility?->hidden((int) $product->getId())) { // lot FICHES-HORS-LIGNE : hors ligne = absent pour le public
            throw new NotFoundHttpException("Produit introuvable.");
        }

        if (!$product->isBookable()) {
             return [];
        }

        return $this->availabilityService->getAvailabilitiesForRange($product, $start, $end);
    }
}