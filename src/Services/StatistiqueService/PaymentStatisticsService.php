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

    public function getPaymentStatistics(?int $orderSource = null): array
    {
        // Paiements pour la semaine en cours
        $currentWeekPayment = $this->paymentsRepository->getTotalPaymentsForCurrentWeek('PaiementClient',$orderSource);
        $dailyPaymentsForCurrentWeek = $this->transformToDailyPaymentList(
            $this->paymentsRepository->getDailyPaymentsForCurrentWeek('PaiementClient',$orderSource)
        );

        // Remboursements pour la semaine en cours
        $currentWeekRefund = $this->paymentsRepository->getTotalPaymentsForCurrentWeek('RemboursementClient',$orderSource);
        $dailyRefundsForCurrentWeek = $this->transformToDailyPaymentList(
            $this->paymentsRepository->getDailyPaymentsForCurrentWeek('RemboursementClient',$orderSource)
        );

        // Paiements pour le mois en cours
        $currentMonthPayment = $this->paymentsRepository->getTotalPaymentsForCurrentMonth('PaiementClient',$orderSource);
        $currentMonthRefund = $this->paymentsRepository->getTotalPaymentsForCurrentMonth('RemboursementClient',$orderSource);

        // Paiements pour l'année en cours
        $currentYearPayment = $this->paymentsRepository->getTotalPaymentsForCurrentYear('PaiementClient',$orderSource);
        $currentYearRefund = $this->paymentsRepository->getTotalPaymentsForCurrentYear('RemboursementClient',$orderSource);

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
