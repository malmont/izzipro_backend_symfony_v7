<?php
namespace App\UseCase\StatistiqueUseCase\PaymentUseCase;

use App\Services\StatistiqueService\PaymentStatisticsService;

class GetPaymentsStatisticsUseCase
{
    private $paymentStatisticsService;

    public function __construct(PaymentStatisticsService $paymentStatisticsService)
    {
        $this->paymentStatisticsService = $paymentStatisticsService;
    }

    public function execute(?int $orderSource = null): array
    {
        return $this->paymentStatisticsService->getPaymentStatistics($orderSource);
    }
}
