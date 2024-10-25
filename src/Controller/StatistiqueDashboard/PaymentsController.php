<?php
namespace App\Controller\StatistiqueDashboard;


use App\UseCase\StatistiqueUseCase\PaymentUseCase\GetPaymentsStatisticsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class PaymentsController extends AbstractController
{
    private $getPaymentsStatisticsUseCase;

    public function __construct(GetPaymentsStatisticsUseCase $getPaymentsStatisticsUseCase)
    {
        $this->getPaymentsStatisticsUseCase = $getPaymentsStatisticsUseCase;
    }

    #[Route('/api/payments/statistics', name: 'get_payments_statistics', methods: ['GET'])]
    public function getPaymentStatistics(): JsonResponse
    {
        $statistics = $this->getPaymentsStatisticsUseCase->execute();

        return $this->json($statistics);
    }
}