<?php
namespace App\Services\StatistiqueService;

use App\Repository\PaymentsRepository;

class PaymentStatisticsService
{
    private $paymentsRepository;

    public function __construct(PaymentsRepository $paymentsRepository)
    {
        $this->paymentsRepository = $paymentsRepository;
    }

    public function getPaymentStatistics(): array
    {
        // Paiements pour la semaine en cours
        $currentWeekPayment = $this->paymentsRepository->getTotalPaymentsForCurrentWeek('PaiementClient');
        $dailyPaymentsForCurrentWeek = $this->paymentsRepository->getDailyPaymentsForCurrentWeek('PaiementClient');

        // Remboursements pour la semaine en cours
        $currentWeekRefund = $this->paymentsRepository->getTotalPaymentsForCurrentWeek('RemboursementClient');
        $dailyRefundsForCurrentWeek = $this->paymentsRepository->getDailyPaymentsForCurrentWeek('RemboursementClient');

        // Paiements pour le mois en cours
        $currentMonthPayment = $this->paymentsRepository->getTotalPaymentsForCurrentMonth('PaiementClient');
        $currentMonthRefund = $this->paymentsRepository->getTotalPaymentsForCurrentMonth('RemboursementClient');

        // Paiements pour l'année en cours
        $currentYearPayment = $this->paymentsRepository->getTotalPaymentsForCurrentYear('PaiementClient');
        $currentYearRefund = $this->paymentsRepository->getTotalPaymentsForCurrentYear('RemboursementClient');

        return [
            'current_week_PaiementClient' => $currentWeekPayment,
            'daily_PaiementClient_for_current_week' => $dailyPaymentsForCurrentWeek,
            'current_week_RemboursementClient' => $currentWeekRefund,
            'daily_RemboursementClient_for_current_week' => $dailyRefundsForCurrentWeek,
            'current_month_PaiementClient' => $currentMonthPayment,
            'current_month_RemboursementClient' => $currentMonthRefund,
            'current_year_PaiementClient' => $currentYearPayment,
            'current_year_RemboursementClient' => $currentYearRefund,
        ];
    }
}
