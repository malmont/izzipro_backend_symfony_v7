<?php

namespace App\Controller\StatistiqueDashboard;


use App\UseCase\StatistiqueUseCase\TaxUseCase\GetMonthlyTaxesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class TaxController extends AbstractController
{
    private $getMonthlyTaxesUseCase;

    public function __construct(GetMonthlyTaxesUseCase $getMonthlyTaxesUseCase)
    {
        $this->getMonthlyTaxesUseCase = $getMonthlyTaxesUseCase;
    }

    #[Route('/api/taxes/monthly', name: 'get_monthly_taxes', methods: ['GET'])]
    public function getMonthlyTaxes(): JsonResponse
    {
        $taxes = $this->getMonthlyTaxesUseCase->execute();

        return $this->json($taxes);
    }
}
