<?php

namespace App\Services\OrderService;

use App\Entity\Product;
use App\Entity\RentalPack;
use App\Services\TenantEntityManagerProvider;

class RentalPriceCalculator
{
    public function __construct(
        private TenantEntityManagerProvider $emProvider
    ) {}


    /**
     * Calculates the total rental price for a product based on duration or dates.
     * 
     * @param Product $product
     * @param array $rentalData Expected keys: 'duration' (float), 'duration_type' (string), 'start' (string), 'end' (string)
     * @return float|null
     */
    public function calculate(Product $product, array $rentalData): ?float
    {
        $duration = $rentalData['duration'] ?? null;
        $type = $rentalData['duration_type'] ?? null;

        // 1. If dates are provided, calculate duration and type dynamically
        if (isset($rentalData['start'], $rentalData['end'])) {
            try {
                $start = new \DateTimeImmutable($rentalData['start']);
                $end = new \DateTimeImmutable($rentalData['end']);
                
                $calculated = $this->calculateDurationFromDates($product, $start, $end);
                $duration = $duration ?? $calculated['duration'];
                $type = $type ?? $calculated['type'];
            } catch (\Exception $e) {
                // If date parsing fails, fallback to provided duration if any
            }
        }

        // Defaults if still null
        $duration = (float)($duration ?? 1);
        $type = $type ?? 'day';

        // 2. Search for the specific RentalPack if ID is provided, else fallback bounds
        $pack = null;
        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(RentalPack::class);
        
        if (!empty($rentalData['rentalPackId'])) {
            $pack = $repo->find($rentalData['rentalPackId']);
        }
        
        if (!$pack) {
            foreach ($product->getCategory() as $category) {
                $pack = $repo->findFirstPackForCategory($category);
                if ($pack) {
                    break;
                }
            }
        }

        if ($pack) {
            $rate = $this->getRateFromPack($pack, $type);
            if ($rate > 0) {
                return (float)$rate * $duration;
            }
        }

        return null; 
    }

    private function calculateDurationFromDates(Product $product, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $config = $product->getBookingConfiguration();
        $granularity = $config ? $config->getGranularity() : 'days';
        
        $diff = $start->diff($end);

        if ($granularity === 'hours') {
            $hours = ($diff->days * 24) + $diff->h + ($diff->i / 60);
            return [
                'duration' => $hours,
                'type' => 'hour'
            ];
        }

        // Default to days
        $days = $diff->days;
        if ($diff->h > 0 || $diff->i > 0) {
            $days += 1; // Round up for partial days
        }

        return [
            'duration' => max(1, $days),
            'type' => 'day'
        ];
    }

    private function getRateFromPack(RentalPack $pack, string $type): float
    {
        return match ($type) {
            'hour' => (float)($pack->getHourRate() ?? 0),
            'halfDay' => (float)($pack->getHalfDayRate() ?? 0),
            'day' => (float)($pack->getDayRate() ?? 0),
            'week' => (float)($pack->getWeekRate() ?? 0),
            'month' => (float)($pack->getMonthRate() ?? 0),
            default => 0.0,
        };
    }
}
