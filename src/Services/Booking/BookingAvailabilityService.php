<?php
// src/Service/Booking/BookingAvailabilityService.php

namespace App\Services\Booking;

use App\Entity\Product;
use App\Entity\Booking;
use App\Enum\ProductMode;
use App\Services\TenantEntityManagerProvider;
use DateTimeInterface;

class BookingAvailabilityService
{
    public function __construct(
        private TenantEntityManagerProvider $emProvider
    ) {}

    private function getRepository()
    {
        return $this->emProvider->getEntityManager()->getRepository(Booking::class);
    }


    public function getRemainingStock(Product $product, DateTimeInterface $start, DateTimeInterface $end): int
    {
        if ($product->getMode() !== ProductMode::BOOKING) {
            return $product->getQuantity();
        }

        $config = $product->getBookingConfiguration();
        if (!$config) {
            return 0;
        }

        $totalStock = $config->getStockQuantity();

        $reservedQuantity = $this->getRepository()->countReservedQuantityBetween(
            $product,
            $start,
            $end
        );

        return $totalStock - $reservedQuantity;
    }

    public function isAvailable(Product $product, DateTimeInterface $start, DateTimeInterface $end, int $quantityRequested = 1): bool
    {
        return $this->getRemainingStock($product, $start, $end) >= $quantityRequested;
    }


    public function getAvailabilitiesForRange(Product $product, DateTimeInterface $start, DateTimeInterface $end): array
    {
        $config = $product->getBookingConfiguration();
        if (!$config) return [];

        $granularity = $config->getGranularity();
        $totalStock = $config->getStockQuantity();

        $intervalSpec = match ($granularity) {
            'days' => 'P1D',
            'hours' => 'PT1H',
            'minutes_30' => 'PT30M',
            'minutes_15' => 'PT15M',
            default => 'P1D'
        };

        $existingBookings = $this->getRepository()->findBookingsOverlapping($product, $start, $end);

        $period = new \DatePeriod($start, new \DateInterval($intervalSpec), $end);
        $results = [];

        foreach ($period as $dt) {
            $slotStart = $dt;
            $slotEnd = \DateTime::createFromInterface($dt)->add(new \DateInterval($intervalSpec));

            $occupied = 0;

            foreach ($existingBookings as $booking) {
                if ($booking->getStartAt() < $slotEnd && $booking->getEndAt() > $slotStart) {
                    $occupied += $booking->getQuantity();
                }
            }

            $remaining = $totalStock - $occupied;
            $results[] = [
                'start' => $slotStart->format('Y-m-d H:i:s'),
                'end' => $slotEnd->format('Y-m-d H:i:s'),
                'remaining' => max(0, $remaining),
                'is_available' => $remaining > 0,
                'status' => $remaining > 0 ? 'available' : 'sold_out'
            ];
        }

        return $results;
    }
}
