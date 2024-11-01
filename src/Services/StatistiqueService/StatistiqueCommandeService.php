<?php

namespace App\Services\StatistiqueService;

use App\Repository\OrderRepository;
use DateTime;

class StatistiqueCommandeService
{
    private $orderRepository;

    public function __construct(OrderRepository $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }

    // Méthode pour obtenir le nombre de commandes pour la semaine en cours, avec filtres
    public function getOrderCountForCurrentWeek(int $typeId, int $statusId, ?int $orderSource = null): int
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week');
        $endOfWeek = (clone $startOfWeek)->modify('sunday this week');

        return $this->orderRepository->getOrderCountBetweenDatesAndFilters($startOfWeek, $endOfWeek, $typeId, $statusId, $orderSource);
    }

    // Méthode pour obtenir le nombre de commandes pour chaque jour de la semaine en cours, avec filtres
    public function getDailyOrderCountForCurrentWeek(int $typeId, int $statusId, ?int $orderSource = null): array
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week');
        $dailyCounts = [];

        for ($i = 0; $i < 7; $i++) {
            $currentDay = (clone $startOfWeek)->modify("+$i day");
            $startOfDay = (clone $currentDay)->setTime(0, 0, 0);
            $endOfDay = (clone $currentDay)->setTime(23, 59, 59);

            $count = $this->orderRepository->getOrderCountBetweenDatesAndFilters($startOfDay, $endOfDay, $typeId, $statusId, $orderSource);
            $dailyCounts[$currentDay->format('Y-m-d')] = $count;
        }

        return $this->transformToDailyOrderList($dailyCounts);
    }

    // Méthode pour obtenir le nombre de commandes pour le mois en cours
    public function getOrderCountForCurrentMonth(?int $orderSource = null): int
    {
        $now = new DateTime();
        $startOfMonth = (clone $now)->modify('first day of this month');
        $endOfMonth = (clone $startOfMonth)->modify('last day of this month');

        return $this->orderRepository->getOrderCountBetweenDates($startOfMonth, $endOfMonth, $orderSource);
    }

    // Méthode pour obtenir le nombre de commandes pour chaque semaine du mois en cours
    public function getWeeklyOrderCountForCurrentMonth(?int $orderSource = null): array
    {
        $now = new DateTime();
        $startOfMonth = (clone $now)->modify('first day of this month');
        $weeklyCounts = [];
        $currentWeekStart = clone $startOfMonth;

        while ($currentWeekStart->format('m') == $now->format('m')) {
            $currentWeekEnd = (clone $currentWeekStart)->modify('sunday this week');
            if ($currentWeekEnd->format('m') != $now->format('m')) {
                $currentWeekEnd = (clone $now)->modify('last day of this month');
            }

            $count = $this->orderRepository->getOrderCountBetweenDates($currentWeekStart, $currentWeekEnd, $orderSource);
            $weeklyCounts[$currentWeekStart->format('W')] = $count;

            $currentWeekStart = (clone $currentWeekEnd)->modify('+1 day');
        }

        return $this->transformToWeeklyOrderList($weeklyCounts);
    }

    // Méthode pour obtenir le nombre de commandes pour l'année en cours
    public function getOrderCountForCurrentYear(?int $orderSource = null): int
    {
        $now = new DateTime();
        $startOfYear = (clone $now)->modify('first day of January');
        $endOfYear = (clone $startOfYear)->modify('last day of December');

        return $this->orderRepository->getOrderCountBetweenDates($startOfYear, $endOfYear, $orderSource);
    }

        /**
         * Méthode pour obtenir le nombre de commandes pour l'année précédente.
         *
         * @param int|null $orderSource
         * @return int
         */
        public function getOrderCountForLastYear(?int $orderSource = null): int
        {
            $now = new DateTime();

            // Début et fin de l'année précédente
            $startOfLastYear = (clone $now)->modify('first day of January last year')->setTime(0, 0);
            $endOfLastYear = (clone $now)->modify('last day of December last year')->setTime(23, 59, 59);

            return $this->orderRepository->getOrderCountBetweenDates($startOfLastYear, $endOfLastYear, $orderSource);
        }

    // Méthode pour obtenir le nombre de commandes pour chaque mois de l'année en cours
    public function getMonthlyOrderCountForCurrentYear(?int $orderSource = null): array
    {
        $now = new DateTime();
        $startOfYear = (clone $now)->modify('first day of January');
        $monthlyCounts = [];

        for ($i = 0; $i < 12; $i++) {
            $currentMonthStart = (clone $startOfYear)->modify("+$i month");
            $currentMonthEnd = (clone $currentMonthStart)->modify('last day of this month');

            if ($currentMonthStart > $now) {
                break;
            }

            $count = $this->orderRepository->getOrderCountBetweenDates($currentMonthStart, $currentMonthEnd, $orderSource);
            $monthlyCounts[$currentMonthStart->format('F')] = $count;
        }

        return $this->transformToMonthlyOrderList($monthlyCounts);
    }

    // Méthode pour obtenir le nombre de commandes pour le mois précédent
    public function getOrderCountForLastMonth(?int $orderSource = null): int
    {
        $now = new DateTime();
        $startOfLastMonth = (clone $now)->modify('first day of previous month');
        $endOfLastMonth = (clone $startOfLastMonth)->modify('last day of this month');

        return $this->orderRepository->getOrderCountBetweenDates($startOfLastMonth, $endOfLastMonth, $orderSource);
    }

    // Méthodes de transformation pour retourner une liste formatée
    private function transformToDailyOrderList(array $dailyData): array
    {
        $dailyOrderList = [];
        foreach ($dailyData as $date => $count) {
            $dailyOrderList[] = [
                'date' => $date,
                'orderCount' => $count,
            ];
        }
        return $dailyOrderList;
    }

    private function transformToWeeklyOrderList(array $weeklyData): array
    {
        $weeklyOrderList = [];
        foreach ($weeklyData as $week => $count) {
            $weeklyOrderList[] = [
                'week' => $week,
                'orderCount' => $count,
            ];
        }
        return $weeklyOrderList;
    }

    private function transformToMonthlyOrderList(array $monthlyData): array
    {
        $monthlyOrderList = [];
        foreach ($monthlyData as $month => $count) {
            $monthlyOrderList[] = [
                'month' => $month,
                'orderCount' => $count,
            ];
        }
        return $monthlyOrderList;
    }
}
