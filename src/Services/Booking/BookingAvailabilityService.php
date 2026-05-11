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

        // --- SÉCURITÉ VÉHICULE ---
        // On vérifie qu'au moins un véhicule est associé à ce produit.
        // Sans véhicule, on ne peut pas synchroniser avec Gemsuite (car_id manquant).
        $vehicleCount = $this->emProvider->getEntityManager()
            ->getRepository(\App\Entity\Vehicle::class)
            ->count(['product' => $product]);

        if ($vehicleCount === 0) {
            return 0;
        }

        $config = $product->getBookingConfiguration();
        if (!$config) {
            return 0;
        }

        $bufferTime = $config->getBufferTime() ?? 0;
        $totalStock = $config->getStockQuantity();
        
        // On calcule l'intervalle effectif
        // Le créneau demandé va de $start à $end + bufferTime
        // Une réservation existante B va de B.start à B.end + bufferTime
        // B chevauche le créneau si : B.start < (end + bufferTime) ET (B.end + bufferTime) > start
        // Équivalent à : B.start < (end + bufferTime) ET B.end > (start - bufferTime)
        
        $effectiveEnd = \DateTimeImmutable::createFromInterface($end);
        if ($bufferTime > 0) {
            $effectiveEnd = $effectiveEnd->modify("+{$bufferTime} minutes");
        }
        
        $effectiveStartForQuery = \DateTimeImmutable::createFromInterface($start);
        if ($bufferTime > 0) {
            $effectiveStartForQuery = $effectiveStartForQuery->modify("-{$bufferTime} minutes");
        }

        $reservedQuantity = $this->getRepository()->countReservedQuantityBetween(
            $product,
            $effectiveStartForQuery,
            $effectiveEnd
        );

        return $totalStock - $reservedQuantity;
    }

    public function isAvailable(Product $product, DateTimeInterface $start, DateTimeInterface $end, int $quantityRequested = 1): bool
    {
        return $this->getRemainingStock($product, $start, $end) >= $quantityRequested;
    }


    public function getAvailabilitiesForRange(Product $product, DateTimeInterface $start, DateTimeInterface $end): array
    {
        // --- SÉCURITÉ VÉHICULE ---
        $vehicleCount = $this->emProvider->getEntityManager()
            ->getRepository(\App\Entity\Vehicle::class)
            ->count(['product' => $product]);

        $config = $product->getBookingConfiguration();
        if (!$config) return [];

        $granularity = $config->getGranularity();
        
        // Si pas de véhicule, le stock effectif est de 0
        $totalStock = ($vehicleCount > 0) ? $config->getStockQuantity() : 0;

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
            $bufferTime = $config->getBufferTime() ?? 0;
            
            $effectiveSlotEnd = \DateTimeImmutable::createFromInterface($slotEnd);
            if ($bufferTime > 0) {
                $effectiveSlotEnd = $effectiveSlotEnd->modify("+{$bufferTime} minutes");
            }

            foreach ($existingBookings as $booking) {
                $bookingEffectiveEnd = \DateTimeImmutable::createFromInterface($booking->getEndAt());
                if ($bufferTime > 0) {
                    $bookingEffectiveEnd = $bookingEffectiveEnd->modify("+{$bufferTime} minutes");
                }
                
                if ($booking->getStartAt() < $effectiveSlotEnd && $bookingEffectiveEnd > $slotStart) {
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
