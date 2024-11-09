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

    #[Route('/api/payments/statistics/{source}', name: 'get_payments_statistics', methods: ['GET'])]
    public function getPaymentStatistics( ?string $source = null): JsonResponse
    {
        $sourceMap = [
            'pos' => 2,
            'ecommerce' => 1,
            'mobile_app' => 3,
        ];
        $orderSource = $sourceMap[$source] ?? null;
        $statistics = $this->getPaymentsStatisticsUseCase->execute($orderSource);

        return $this->json($statistics);
    }
}