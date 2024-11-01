<?php

namespace App\Services\StatistiqueService;

use App\Repository\OrderRepository;

class CarrierStatisticsService
{
    private $orderRepository;

    public function __construct(OrderRepository $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }

    public function getMonthlyCarrierStatisticsForCurrentYear(): array
    {
        $currentYear = (int)date('Y');
        $carrierData = $this->orderRepository->getTotalCarrierByMonth($currentYear);

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
     * Transforme les données de transport mensuelles en un objet structuré.
     *
     * @param string $month Nom du mois
     * @param int $carrierCount Nombre de transporteurs pour le mois
     * @return array
     */
    private function transformToMonthlyCarrierObject(string $month, int $carrierCount): array
    {
        return [
            'month' => $month,
            'carrier_count' => $carrierCount
        ];
    }
}
