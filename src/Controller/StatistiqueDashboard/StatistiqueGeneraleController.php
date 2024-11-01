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
use App\UseCase\StatistiqueUseCase\StatistiqueCommandeUseCase\CalculateOrderCountForLastYearUseCase;

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
    private $calculateOrderCountForLastYearUseCase;

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
        CalculateMonthlyAverageOrderValueForCurrentYearUseCase $calculateMonthlyAverageOrderValueForCurrentYearUseCase,
        CalculateOrderCountForLastYearUseCase $calculateOrderCountForLastYearUseCase
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
        $this->calculateOrderCountForLastYearUseCase = $calculateOrderCountForLastYearUseCase;
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
            'currentWeekRevenue' => $currentWeekRevenue,
            'lastWeekRevenue' => $lastWeekRevenue,
            'dailyRevenueForCurrentWeek' => $dailyRevenueForCurrentWeek,
            'currentMonthRevenue' => $currentMonthRevenue,
            'lastMonthRevenue' => $lastMonthRevenue,
            'weeklyRevenueForCurrentMonth' => $weeklyRevenueForCurrentMonth,
            'currentYearRevenue' => $currentYearRevenue,
            'lastYearRevenue' => $lastYearRevenue,
            'monthlyRevenueForCurrentYear' => $monthlyRevenueForCurrentYear,
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

                $statistics["currentWeekCount{$typeName}{$statusName}"] = $currentWeekCount;
                $statistics["lastWeekCount{$typeName}{$statusName}"] = $lastWeekCount;
                $statistics["dailyCount{$typeName}_{$statusName}ForCurrentWeek"] = $dailyCountForCurrentWeek;
            }
            }
        }

        // Calcul des autres statistiques
        $statistics["currentMonthCount"] = $this->calculateOrderCountForCurrentMonthUseCase->execute($orderSource);
        $statistics["lastMonthCount"] = $this->calculateOrderCountForLastMonthUseCase->execute($orderSource);
        $statistics["weeklyCountForCurrentMonth"] = $this->calculateWeeklyOrderCountForCurrentMonthUseCase->execute($orderSource);
        $statistics["currentYearCount"] = $this->calculateOrderCountForCurrentYearUseCase->execute($orderSource);
        $statistics["lastYearCount"] = $this->calculateOrderCountForLastYearUseCase->execute($orderSource);
        $statistics["monthlyCountForCurrentYear"] = $this->calculateMonthlyOrderCountForCurrentYearUseCase->execute($orderSource);

        return $this->json($statistics);
    }



    /**
     * @Route("/api/statistiques/panier-moyen/{source}", name="statistiques_panier_moyen", methods={"GET"})
     */
    public function getAverageOrderValueStatistics(Request $request, ?string $source = null): JsonResponse
    {
        $sourceMap = [
            'pos' => 2,
            'ecommerce' => 1,
            'mobile_app' => 3,
        ];
        $orderSource = $sourceMap[$source] ?? null;

        $currentWeekAverage = $this->calculateAverageOrderValueForCurrentWeekUseCase->execute($orderSource);
        $lastWeekAverage = $this->calculateAverageOrderValueForLastWeekUseCase->execute($orderSource);
        $dailyAverageForCurrentWeek = $this->calculateDailyAverageOrderValueForCurrentWeekUseCase->execute($orderSource);

        $currentMonthAverage = $this->calculateAverageOrderValueForCurrentMonthUseCase->execute($orderSource);
        $lastMonthAverage = $this->calculateAverageOrderValueForLastMonthUseCase->execute($orderSource);
        $weeklyAverageForCurrentMonth = $this->calculateWeeklyAverageOrderValueForCurrentMonthUseCase->execute($orderSource);

        $currentYearAverage = $this->calculateAverageOrderValueForCurrentYearUseCase->execute($orderSource);
        $monthlyAverageForCurrentYear = $this->calculateMonthlyAverageOrderValueForCurrentYearUseCase->execute($orderSource);

        return $this->json([
            "currentWeekAverage" => $currentWeekAverage,
            "lastWeekAverage" => $lastWeekAverage,
            "dailyAverageForCurrentWeek" => $dailyAverageForCurrentWeek,
            "currentMonthAverage" => $currentMonthAverage,
            "lastAverageCount" => $lastMonthAverage,
            "weeklyAverageForCurrentMonth" => $weeklyAverageForCurrentMonth,
            "currentYearAverage" => $currentYearAverage,
            "monthlyAverageForCurrentYear" => $monthlyAverageForCurrentYear,
        ]);
    }
}
