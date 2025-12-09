<?php

namespace App\UseCase\Booking;

use App\Dto\AvailabilityCheckDto;
use App\Entity\Product;
use App\Services\Booking\BookingAvailabilityService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CheckAvailabilityUseCase
{
    public function __construct(
        private BookingAvailabilityService $availabilityService,
        private TenantEntityManagerProvider $emProvider
    ) {}

    public function execute(AvailabilityCheckDto $dto): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $product = $tenantEm->getRepository(Product::class)->find($dto->productId);

        if (!$product) {
            throw new NotFoundHttpException("Produit introuvable.");
        }

        $remaining = $this->availabilityService->getRemainingStock(
            $product,
            $dto->startAt,
            $dto->endAt
        );

        $isAvailable = $remaining >= $dto->quantity;

        return [
            'available' => $isAvailable,
            'remaining_stock' => max(0, $remaining),
            'quantity_requested' => $dto->quantity,
            'product_name' => $product->getName()
        ];
    }
}