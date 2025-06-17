<?php
namespace App\Services\StatistiqueService;

use App\Entity\Payments; // <-- On importe l'entité
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider

class PaymentStatisticsService
{
    // MODIFICATION 1 : Le service ne dépend plus que du provider
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getPaymentStatistics(?int $orderSource = null): array
    {
        // MODIFICATION 2 : On récupère l'EM et le repository ici
        $em = $this->emProvider->getEntityManager();
        $paymentsRepository = $em->getRepository(Payments::class);

        // On utilise le repository obtenu depuis l'EM du tenant
        $currentWeekPayment = $paymentsRepository->getTotalPaymentsForCurrentWeek('PaiementClient', $orderSource);
        $dailyPaymentsForCurrentWeek = $this->transformToDailyPaymentList(
            $paymentsRepository->getDailyPaymentsForCurrentWeek('PaiementClient', $orderSource)
        );

        $currentWeekRefund = $paymentsRepository->getTotalPaymentsForCurrentWeek('RemboursementClient', $orderSource);
        $dailyRefundsForCurrentWeek = $this->transformToDailyPaymentList(
            $paymentsRepository->getDailyPaymentsForCurrentWeek('RemboursementClient', $orderSource)
        );

        $currentMonthPayment = $paymentsRepository->getTotalPaymentsForCurrentMonth('PaiementClient', $orderSource);
        $currentMonthRefund = $paymentsRepository->getTotalPaymentsForCurrentMonth('RemboursementClient', $orderSource);

        $currentYearPayment = $paymentsRepository->getTotalPaymentsForCurrentYear('PaiementClient', $orderSource);
        $currentYearRefund = $paymentsRepository->getTotalPaymentsForCurrentYear('RemboursementClient', $orderSource);

        // Le reste de votre logique de construction de tableau est inchangée
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

    /**
     * INCHANGÉ : Cette méthode privée est une logique pure, pas de modification nécessaire.
     */
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