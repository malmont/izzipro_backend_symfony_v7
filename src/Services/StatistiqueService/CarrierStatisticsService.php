<?php
namespace App\Services\StatistiqueService;

use App\Entity\Order; 
use App\Services\TenantEntityManagerProvider; 

class CarrierStatisticsService
{

    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getMonthlyCarrierStatisticsForCurrentYear(): array
    {

        $em = $this->emProvider->getEntityManager();
        $orderRepository = $em->getRepository(Order::class);

        $currentYear = (int)date('Y');
        $carrierData = $orderRepository->getTotalCarrierByMonth($currentYear);

        $monthlyCarrierData = [];
        $moisNoms = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
        ];

        foreach ($moisNoms as $monthNum => $monthName) {
            $monthlyCarrierData[] = $this->transformToMonthlyCarrierObject($monthName, $carrierData[$monthNum] ?? 0);
        }

        return ['monthly_carrier_for_current_year' => $monthlyCarrierData];
    }

    /**
     * INCHANGÉ : Cette méthode privée est une logique pure, pas de modification nécessaire.
     */
    private function transformToMonthlyCarrierObject(string $month, int $carrierCount): array
    {
        return [
            'month' => $month,
            'carrier_count' => $carrierCount
        ];
    }
}