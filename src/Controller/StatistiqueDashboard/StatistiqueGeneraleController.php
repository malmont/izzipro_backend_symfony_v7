<?php

namespace App\Controller\StatistiqueDashboard;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\UseCase\StatistiqueUseCase\StatistiqueRevenuUseCase\CalculateWeeklyRevenueUseCase;
use App\UseCase\StatistiqueUseCase\StatistiqueRevenuUseCase\CalculateMonthlyRevenueUseCase;
use App\UseCase\StatistiqueUseCase\StatistiqueRevenuUseCase\CalculateYearlyRevenueUseCase;
use App\UseCase\StatistiqueUseCase\StatistiqueRevenuUseCase\CalculateCustomIntervalRevenueUseCase;
use App\UseCase\StatistiqueUseCase\StatistiqueRevenuUseCase\CalculateDailyRevenueForCurrentWeekUseCase;
use App\UseCase\StatistiqueUseCase\StatistiqueRevenuUseCase\CalculateWeeklyRevenueForCurrentMonthUseCase;
use App\UseCase\StatistiqueUseCase\StatistiqueRevenuUseCase\CalculateMonthlyRevenueForCurrentYearUseCase;
use App\UseCase\StatistiqueUseCase\StatistiqueCommandeUseCase\CalculateOrderCountForCurrentWeekUseCase;
use App\UseCase\StatistiqueUseCase\StatistiqueCommandeUseCase\CalculateDailyOrderCountForCurrentWeekUseCase;
use App\UseCase\StatistiqueUseCase\StatistiqueCommandeUseCase\CalculateOrderCountForCurrentMonthUseCase;
use App\UseCase\StatistiqueUseCase\StatistiqueCommandeUseCase\CalculateWeeklyOrderCountForCurrentMonthUseCase;
use App\UseCase\StatistiqueUseCase\StatistiqueCommandeUseCase\CalculateOrderCountForCurrentYearUseCase;
use App\UseCase\StatistiqueUseCase\StatistiqueCommandeUseCase\CalculateMonthlyOrderCountForCurrentYearUseCase;
use App\UseCase\StatistiqueUseCase\StatistiqueCommandeUseCase\CalculateOrderCountForLastMonthUseCase;
use App\UseCase\StatistiqueUseCase\StatistiquePanierUseCase\CalculateAverageOrderValueForCurrentWeekUseCase;
use App\UseCase\StatistiqueUseCase\StatistiquePanierUseCase\CalculateAverageOrderValueForLastWeekUseCase;
use App\UseCase\StatistiqueUseCase\StatistiquePanierUseCase\CalculateDailyAverageOrderValueForCurrentWeekUseCase;
use App\UseCase\StatistiqueUseCase\StatistiquePanierUseCase\CalculateAverageOrderValueForCurrentMonthUseCase;
use App\UseCase\StatistiqueUseCase\StatistiquePanierUseCase\CalculateAverageOrderValueForLastMonthUseCase;
use App\UseCase\StatistiqueUseCase\StatistiquePanierUseCase\CalculateWeeklyAverageOrderValueForCurrentMonthUseCase;
use App\UseCase\StatistiqueUseCase\StatistiquePanierUseCase\CalculateAverageOrderValueForCurrentYearUseCase;
use App\UseCase\StatistiqueUseCase\StatistiquePanierUseCase\CalculateMonthlyAverageOrderValueForCurrentYearUseCase;

use Symfony\Component\HttpFoundation\Request;

use DateTime;

class StatistiqueGeneraleController extends AbstractController
{
    private $calculateWeeklyRevenueUseCase;
    private $calculateMonthlyRevenueUseCase;
    private $calculateYearlyRevenueUseCase;
    private $calculateCustomIntervalRevenueUseCase;
    private $calculateDailyRevenueForCurrentWeekUseCase;
    private $calculateWeeklyRevenueForCurrentMonthUseCase;
    private $calculateMonthlyRevenueForCurrentYearUseCase;
    private $calculateOrderCountForCurrentWeekUseCase;
    private $calculateDailyOrderCountForCurrentWeekUseCase;
    private $calculateOrderCountForCurrentMonthUseCase;
    private $calculateWeeklyOrderCountForCurrentMonthUseCase;
    private $calculateOrderCountForCurrentYearUseCase;
    private $calculateMonthlyOrderCountForCurrentYearUseCase;
    private $calculateOrderCountForLastMonthUseCase;
    private $calculateAverageOrderValueForCurrentWeekUseCase;
    private $calculateAverageOrderValueForLastWeekUseCase;
    private $calculateDailyAverageOrderValueForCurrentWeekUseCase;
    private $calculateAverageOrderValueForCurrentMonthUseCase;
    private $calculateAverageOrderValueForLastMonthUseCase;
    private $calculateWeeklyAverageOrderValueForCurrentMonthUseCase;
    private $calculateAverageOrderValueForCurrentYearUseCase;
    private $calculateMonthlyAverageOrderValueForCurrentYearUseCase;

    public function __construct(
        CalculateWeeklyRevenueUseCase $calculateWeeklyRevenueUseCase,
        CalculateMonthlyRevenueUseCase $calculateMonthlyRevenueUseCase,
        CalculateYearlyRevenueUseCase $calculateYearlyRevenueUseCase,
        CalculateCustomIntervalRevenueUseCase $calculateCustomIntervalRevenueUseCase,
        CalculateDailyRevenueForCurrentWeekUseCase $calculateDailyRevenueForCurrentWeekUseCase,
        CalculateWeeklyRevenueForCurrentMonthUseCase $calculateWeeklyRevenueForCurrentMonthUseCase,
        CalculateMonthlyRevenueForCurrentYearUseCase $calculateMonthlyRevenueForCurrentYearUseCase,
        CalculateOrderCountForCurrentWeekUseCase $calculateOrderCountForCurrentWeekUseCase,
        CalculateDailyOrderCountForCurrentWeekUseCase $calculateDailyOrderCountForCurrentWeekUseCase,
        CalculateOrderCountForCurrentMonthUseCase $calculateOrderCountForCurrentMonthUseCase,
        CalculateWeeklyOrderCountForCurrentMonthUseCase $calculateWeeklyOrderCountForCurrentMonthUseCase,
        CalculateOrderCountForCurrentYearUseCase $calculateOrderCountForCurrentYearUseCase,
        CalculateMonthlyOrderCountForCurrentYearUseCase $calculateMonthlyOrderCountForCurrentYearUseCase,
        CalculateOrderCountForLastMonthUseCase $calculateOrderCountForLastMonthUseCase,
        CalculateAverageOrderValueForCurrentWeekUseCase $calculateAverageOrderValueForCurrentWeekUseCase,
        CalculateAverageOrderValueForLastWeekUseCase $calculateAverageOrderValueForLastWeekUseCase,
        CalculateDailyAverageOrderValueForCurrentWeekUseCase $calculateDailyAverageOrderValueForCurrentWeekUseCase,
        CalculateAverageOrderValueForCurrentMonthUseCase $calculateAverageOrderValueForCurrentMonthUseCase,
        CalculateAverageOrderValueForLastMonthUseCase $calculateAverageOrderValueForLastMonthUseCase,
        CalculateWeeklyAverageOrderValueForCurrentMonthUseCase $calculateWeeklyAverageOrderValueForCurrentMonthUseCase,
        CalculateAverageOrderValueForCurrentYearUseCase $calculateAverageOrderValueForCurrentYearUseCase,
        CalculateMonthlyAverageOrderValueForCurrentYearUseCase $calculateMonthlyAverageOrderValueForCurrentYearUseCase
    ) {
        $this->calculateWeeklyRevenueUseCase = $calculateWeeklyRevenueUseCase;
        $this->calculateMonthlyRevenueUseCase = $calculateMonthlyRevenueUseCase;
        $this->calculateYearlyRevenueUseCase = $calculateYearlyRevenueUseCase;
        $this->calculateCustomIntervalRevenueUseCase = $calculateCustomIntervalRevenueUseCase;
        $this->calculateDailyRevenueForCurrentWeekUseCase = $calculateDailyRevenueForCurrentWeekUseCase;
        $this->calculateWeeklyRevenueForCurrentMonthUseCase = $calculateWeeklyRevenueForCurrentMonthUseCase;
        $this->calculateMonthlyRevenueForCurrentYearUseCase = $calculateMonthlyRevenueForCurrentYearUseCase;
        $this->calculateOrderCountForCurrentWeekUseCase = $calculateOrderCountForCurrentWeekUseCase;
        $this->calculateDailyOrderCountForCurrentWeekUseCase = $calculateDailyOrderCountForCurrentWeekUseCase;
        $this->calculateOrderCountForCurrentMonthUseCase = $calculateOrderCountForCurrentMonthUseCase;
        $this->calculateWeeklyOrderCountForCurrentMonthUseCase = $calculateWeeklyOrderCountForCurrentMonthUseCase;
        $this->calculateOrderCountForCurrentYearUseCase = $calculateOrderCountForCurrentYearUseCase;
        $this->calculateMonthlyOrderCountForCurrentYearUseCase = $calculateMonthlyOrderCountForCurrentYearUseCase;
        $this->calculateOrderCountForLastMonthUseCase = $calculateOrderCountForLastMonthUseCase;
        $this->calculateAverageOrderValueForCurrentWeekUseCase = $calculateAverageOrderValueForCurrentWeekUseCase;
        $this->calculateAverageOrderValueForLastWeekUseCase = $calculateAverageOrderValueForLastWeekUseCase;
        $this->calculateDailyAverageOrderValueForCurrentWeekUseCase = $calculateDailyAverageOrderValueForCurrentWeekUseCase;
        $this->calculateAverageOrderValueForCurrentMonthUseCase = $calculateAverageOrderValueForCurrentMonthUseCase;
        $this->calculateAverageOrderValueForLastMonthUseCase = $calculateAverageOrderValueForLastMonthUseCase;
        $this->calculateWeeklyAverageOrderValueForCurrentMonthUseCase = $calculateWeeklyAverageOrderValueForCurrentMonthUseCase;
        $this->calculateAverageOrderValueForCurrentYearUseCase = $calculateAverageOrderValueForCurrentYearUseCase;
        $this->calculateMonthlyAverageOrderValueForCurrentYearUseCase = $calculateMonthlyAverageOrderValueForCurrentYearUseCase;
    }

    /**
     * @Route("/api/statistiques/chiffre-affaires/{source}", name="statistiques_chiffre_affaires", methods={"GET"})
     */
    public function getChiffreAffaires(Request $request, ?string $source = null): JsonResponse
    {
        $startDateParam = $request->query->get('startDate');
        $endDateParam = $request->query->get('endDate');

        // Associer les sources de commande aux valeurs d'enum
        $sourceMap = [
            'pos' => 2,
            'ecommerce' => 1,
            'mobile_app' => 3,
        ];

        // Définir `orderSource` en fonction de l'URL ou null pour tous
        $orderSource = $sourceMap[$source] ?? null;

        if ($startDateParam && $endDateParam) {
            try {
                $startDate = new DateTime($startDateParam);
                $endDate = new DateTime($endDateParam);
                $customRevenue = $this->calculateCustomIntervalRevenueUseCase->execute($startDate, $endDate, $orderSource);

                return $this->json([
                    'startDate' => $startDate->format('Y-m-d'),
                    'endDate' => $endDate->format('Y-m-d'),
                    'revenue' => $customRevenue,
                ]);
            } catch (\Exception $e) {
                return $this->json(['error' => 'Format de date invalide'], JsonResponse::HTTP_BAD_REQUEST);
            }
        }

        $currentWeekRevenue = $this->calculateWeeklyRevenueUseCase->execute(0, $orderSource);
        $lastWeekRevenue = $this->calculateWeeklyRevenueUseCase->execute(1, $orderSource);
        $dailyRevenueForCurrentWeek = $this->calculateDailyRevenueForCurrentWeekUseCase->execute($orderSource);

        $currentMonthRevenue = $this->calculateMonthlyRevenueUseCase->execute(0, $orderSource);
        $lastMonthRevenue = $this->calculateMonthlyRevenueUseCase->execute(1, $orderSource);
        $weeklyRevenueForCurrentMonth = $this->calculateWeeklyRevenueForCurrentMonthUseCase->execute($orderSource);

        $currentYearRevenue = $this->calculateYearlyRevenueUseCase->execute(0, $orderSource);
        $lastYearRevenue = $this->calculateYearlyRevenueUseCase->execute(1, $orderSource);
        $monthlyRevenueForCurrentYear = $this->calculateMonthlyRevenueForCurrentYearUseCase->execute($orderSource);

        return $this->json([
            'current_week_revenue' => $currentWeekRevenue,
            'last_week_revenue' => $lastWeekRevenue,
            'daily_revenue_for_current_week' => $dailyRevenueForCurrentWeek,
            'current_month_revenue' => $currentMonthRevenue,
            'last_month_revenue' => $lastMonthRevenue,
            'weekly_revenue_for_current_month' => $weeklyRevenueForCurrentMonth,
            'current_year_revenue' => $currentYearRevenue,
            'last_year_revenue' => $lastYearRevenue,
            'monthly_revenue_for_current_year' => $monthlyRevenueForCurrentYear,
        ]);
    }


        /**
     * @Route("/api/statistiques/nombre-commandes/{source}", name="statistiques_nombre_commandes", methods={"GET"}, defaults={"source"=null})
     */
    public function getOrderStatistics(Request $request, ?string $source = null): JsonResponse
    {
        $types = [1 => 'AchatClient', 2 => 'RetourClient'];
        $statuses = [3 => 'Complétée', 6 => 'Livrée', 7 => 'Annulation'];

        $sourceMap = [
            'pos' => 2,
            'ecommerce' => 1,
            'mobile_app' => 3,
        ];

        // Définir `orderSource` en fonction de l'URL ou null pour tous
        $orderSource = $sourceMap[$source] ?? null;
        $statistics = [];

        foreach ($types as $typeId => $typeName) {
            foreach ($statuses as $statusId => $statusName) {
                if(!($typeId == 2 &&  ($statusId==7)) ){
                // Calculs pour la semaine en cours
                $currentWeekCount = $this->calculateOrderCountForCurrentWeekUseCase->execute($typeId, $statusId,$orderSource);
                $lastWeekCount = $this->calculateOrderCountForCurrentWeekUseCase->execute($typeId, $statusId,$orderSource);
                $dailyCountForCurrentWeek = $this->calculateDailyOrderCountForCurrentWeekUseCase->execute($typeId, $statusId,$orderSource);

                // Ajout des résultats au tableau de réponse

                $statistics["current_week_count_{$typeName}_{$statusName}"] = $currentWeekCount;
                $statistics["last_week_count_{$typeName}_{$statusName}"] = $lastWeekCount;
                $statistics["daily_count_{$typeName}_{$statusName}_for_current_week"] = $dailyCountForCurrentWeek;
            }
            }
        }

        // Calcul des autres statistiques
        $statistics["current_month_count"] = $this->calculateOrderCountForCurrentMonthUseCase->execute($orderSource);
        $statistics["last_month_count"] = $this->calculateOrderCountForLastMonthUseCase->execute($orderSource);
        $statistics["weekly_count_for_current_month"] = $this->calculateWeeklyOrderCountForCurrentMonthUseCase->execute($orderSource);
        $statistics["current_year_count"] = $this->calculateOrderCountForCurrentYearUseCase->execute($orderSource);
        $statistics["monthly_count_for_current_year"] = $this->calculateMonthlyOrderCountForCurrentYearUseCase->execute($orderSource);

        return $this->json($statistics);
    }



    /**
     * @Route("/api/statistiques/panier-moyen", name="statistiques_panier_moyen", methods={"GET"})
     */
    public function getAverageOrderValueStatistics(): JsonResponse
    {
        $currentWeekAverage = $this->calculateAverageOrderValueForCurrentWeekUseCase->execute();
        $lastWeekAverage = $this->calculateAverageOrderValueForLastWeekUseCase->execute();
        $dailyAverageForCurrentWeek = $this->calculateDailyAverageOrderValueForCurrentWeekUseCase->execute();

        $currentMonthAverage = $this->calculateAverageOrderValueForCurrentMonthUseCase->execute();
        $lastMonthAverage = $this->calculateAverageOrderValueForLastMonthUseCase->execute();
        $weeklyAverageForCurrentMonth = $this->calculateWeeklyAverageOrderValueForCurrentMonthUseCase->execute();

        $currentYearAverage = $this->calculateAverageOrderValueForCurrentYearUseCase->execute();
        $monthlyAverageForCurrentYear = $this->calculateMonthlyAverageOrderValueForCurrentYearUseCase->execute();

        return $this->json([
            "current_week_average" => $currentWeekAverage,
            "last_week_average" => $lastWeekAverage,
            "daily_average_for_current_week" => $dailyAverageForCurrentWeek,
            "current_month_average" => $currentMonthAverage,
            "last_average_count" => $lastMonthAverage,
            "weekly_average_for_current_month" => $weeklyAverageForCurrentMonth,
            "current_year_average" => $currentYearAverage,
            "monthly_average_for_current_year" => $monthlyAverageForCurrentYear,
        ]);
    }
}
