<?php

namespace App\Controller\StatistiqueDashboard;

use App\UseCase\StatistiqueUseCase\StatistiqueCarrierUseCase\GetMonthlyCarrierStatisticsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class CarrierStatisticsController extends AbstractController
{
    private $getMonthlyCarrierStatisticsUseCase;

    public function __construct(GetMonthlyCarrierStatisticsUseCase $getMonthlyCarrierStatisticsUseCase)
    {
        $this->getMonthlyCarrierStatisticsUseCase = $getMonthlyCarrierStatisticsUseCase;
    }

    #[Route('/api/carrier/statistics', name: 'get_carrier_statistics', methods: ['GET'])]
    public function getCarrierStatistics(): JsonResponse
    {
        $data = $this->getMonthlyCarrierStatisticsUseCase->execute();
        return $this->json($data);
    }
}
