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

        $monthlyCarrierData = [
            'January' => $carrierData[1] ?? 0,
            'February' => $carrierData[2] ?? 0,
            'March' => $carrierData[3] ?? 0,
            'April' => $carrierData[4] ?? 0,
            'May' => $carrierData[5] ?? 0,
            'June' => $carrierData[6] ?? 0,
            'July' => $carrierData[7] ?? 0,
            'August' => $carrierData[8] ?? 0,
            'September' => $carrierData[9] ?? 0,
            'October' => $carrierData[10] ?? 0,
            'November' => $carrierData[11] ?? 0,
            'December' => $carrierData[12] ?? 0,
        ];

        return ['monthly_carrier_for_current_year' => $monthlyCarrierData];
    }
}
