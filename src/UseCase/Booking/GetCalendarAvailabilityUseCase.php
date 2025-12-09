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
        private TenantEntityManagerProvider $emProvider
    ) {}

    public function execute(int $productId, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $product = $tenantEm->getRepository(Product::class)->find($productId);

        if (!$product) {
            throw new NotFoundHttpException("Produit introuvable.");
        }

        if (!$product->isBookable()) {
             return [];
        }

        return $this->availabilityService->getAvailabilitiesForRange($product, $start, $end);
    }
}