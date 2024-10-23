<?php

namespace App\Controller\StockStatisticsController;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\UseCase\StockEvolution\GetStockEvolutionForCurrentWeekUseCase;
use App\UseCase\StockEvolution\GetStockEvolutionForCurrentMonthUseCase;
use App\UseCase\StockEvolution\GetStockEvolutionForCurrentYearUseCase;


class StockStatisticsController extends AbstractController
{
    private $getStockEvolutionForCurrentWeekUseCase;
    private $getStockEvolutionForCurrentMonthUseCase;
    private $getStockEvolutionForCurrentYearUseCase;

    public function __construct(
        GetStockEvolutionForCurrentWeekUseCase $getStockEvolutionForCurrentWeekUseCase,
        GetStockEvolutionForCurrentMonthUseCase $getStockEvolutionForCurrentMonthUseCase,
        GetStockEvolutionForCurrentYearUseCase $getStockEvolutionForCurrentYearUseCase
    ) {
        $this->getStockEvolutionForCurrentWeekUseCase = $getStockEvolutionForCurrentWeekUseCase;
        $this->getStockEvolutionForCurrentMonthUseCase = $getStockEvolutionForCurrentMonthUseCase;
        $this->getStockEvolutionForCurrentYearUseCase = $getStockEvolutionForCurrentYearUseCase;
    }

    /**
     * @Route("/api/stock-evolution", name="stock_evolution_all", methods={"GET"})
     */
    public function getStockEvolution(): JsonResponse
    {
        $weekData = $this->getStockEvolutionForCurrentWeekUseCase->execute();
        $monthData = $this->getStockEvolutionForCurrentMonthUseCase->execute();
        $yearData = $this->getStockEvolutionForCurrentYearUseCase->execute();

        return $this->json([
            'stock_evolution_week' => $weekData,
            'stock_evolution_month' => $monthData,
            'stock_evolution_year' => $yearData,
        ]);
    }
}