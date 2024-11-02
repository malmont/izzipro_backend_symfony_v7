<?php

namespace App\Controller\StockStatisticsController;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\UseCase\StockValue\GetStockValueForCurrentMonthUseCase;
use App\UseCase\StockValue\GetStockValueForLastMonthUseCase;
use App\UseCase\StockValue\GetStockValueForTwoMonthsAgoUseCase;
use App\UseCase\StockValue\GetStockValueForCurrentUseCase;

class StockValueController extends AbstractController
{
    private $getStockValueForCurrentMonthUseCase;
    private $getStockValueForLastMonthUseCase;
    private $getStockValueForTwoMonthsAgoUseCase;
    private $getStockValueForCurrentUseCase;

    public function __construct(
        GetStockValueForCurrentMonthUseCase $getStockValueForCurrentMonthUseCase,
        GetStockValueForLastMonthUseCase $getStockValueForLastMonthUseCase,
        GetStockValueForTwoMonthsAgoUseCase $getStockValueForTwoMonthsAgoUseCase,
        GetStockValueForCurrentUseCase $getStockValueForCurrentUseCase
    ) {
        $this->getStockValueForCurrentMonthUseCase = $getStockValueForCurrentMonthUseCase;
        $this->getStockValueForLastMonthUseCase = $getStockValueForLastMonthUseCase;
        $this->getStockValueForTwoMonthsAgoUseCase = $getStockValueForTwoMonthsAgoUseCase;
        $this->getStockValueForCurrentUseCase = $getStockValueForCurrentUseCase;
    }

    /**
     * @Route("/api/stock-value", name="stock_value_all", methods={"GET"})
     */
    public function getStockValues(): JsonResponse
    {
        // $currentMonthValue = $this->getStockValueForCurrentMonthUseCase->execute();
        $lastMonthValue = $this->getStockValueForLastMonthUseCase->execute();
        $twoMonthsAgoValue = $this->getStockValueForTwoMonthsAgoUseCase->execute();
        $currentMonthValue = $this->getStockValueForCurrentUseCase->execute();

        return $this->json([
            'stock_value_current_month' => $currentMonthValue,
            'stock_value_last_month' => $lastMonthValue,
            'stock_value_two_months_ago' => $twoMonthsAgoValue,
        ]);
    }
}