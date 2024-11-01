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
        $dailyPaymentsForCurrentWeek = $this->transformToDailyPaymentList(
            $this->paymentsRepository->getDailyPaymentsForCurrentWeek('PaiementClient')
        );

        // Remboursements pour la semaine en cours
        $currentWeekRefund = $this->paymentsRepository->getTotalPaymentsForCurrentWeek('RemboursementClient');
        $dailyRefundsForCurrentWeek = $this->transformToDailyPaymentList(
            $this->paymentsRepository->getDailyPaymentsForCurrentWeek('RemboursementClient')
        );

        // Paiements pour le mois en cours
        $currentMonthPayment = $this->paymentsRepository->getTotalPaymentsForCurrentMonth('PaiementClient');
        $currentMonthRefund = $this->paymentsRepository->getTotalPaymentsForCurrentMonth('RemboursementClient');

        // Paiements pour l'année en cours
        $currentYearPayment = $this->paymentsRepository->getTotalPaymentsForCurrentYear('PaiementClient');
        $currentYearRefund = $this->paymentsRepository->getTotalPaymentsForCurrentYear('RemboursementClient');

        return [
            'current_week' => [
                'PaiementClient' => [
                    'total' => $currentWeekPayment,
                    'daily' => $dailyPaymentsForCurrentWeek
                ],
                'RemboursementClient' => [
                    'total' => $currentWeekRefund,
                    'daily' => $dailyRefundsForCurrentWeek
                ]
            ],
            'current_month' => [
                'PaiementClient' => $currentMonthPayment,
                'RemboursementClient' => $currentMonthRefund
            ],
            'current_year' => [
                'PaiementClient' => $currentYearPayment,
                'RemboursementClient' => $currentYearRefund
            ]
        ];
    }

    // Transforme les données de paiements journaliers en une liste structurée
    private function transformToDailyPaymentList(array $dailyData): array
    {
        $dailyPaymentList = [];
        foreach ($dailyData as $date => $amount) {
            $dailyPaymentList[] = [
                'date' => $date,
                'amount' => $amount
            ];
        }
        return $dailyPaymentList;
    }
}
