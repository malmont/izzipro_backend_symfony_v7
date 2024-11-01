<?php

namespace App\Services\StatistiqueService;

use App\Repository\OrderRepository;
use DateTime;

class StatistiqueRevenuService
{
    private $orderRepository;

    public function __construct(OrderRepository $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }

    // Méthode de calcul pour les revenus journaliers de la semaine en cours
    public function getDailyRevenueForCurrentWeek(?int $orderSource = null): array
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week');
        $dailyRevenues = [];

        for ($i = 0; $i < 7; $i++) {
            $currentDay = (clone $startOfWeek)->modify("+$i day");
            $startDate = (clone $currentDay)->setTime(0, 0, 0);
            $endDate = (clone $currentDay)->setTime(23, 59, 59);
            
            $revenue = $this->orderRepository->getTotalRevenueBetweenDates($startDate, $endDate, $orderSource);
            $dailyRevenues[$currentDay->format('Y-m-d')] = $revenue;
        }

        return $this->transformToDailyRevenueList($dailyRevenues);
    }

    // Méthode de calcul pour les revenus hebdomadaires du mois en cours
    public function getWeeklyRevenueForCurrentMonth(?int $orderSource = null): array
    {
        $now = new DateTime();
        $startOfMonth = (clone $now)->modify('first day of this month');
        $weeklyRevenues = [];
        $currentWeekStart = clone $startOfMonth;

        while ($currentWeekStart->format('m') == $now->format('m')) {
            $currentWeekEnd = (clone $currentWeekStart)->modify('sunday this week');
            if ($currentWeekEnd->format('m') != $now->format('m')) {
                $currentWeekEnd = (clone $now)->modify('last day of this month');
            }

            $revenue = $this->orderRepository->getTotalRevenueBetweenDates($currentWeekStart, $currentWeekEnd, $orderSource);
            $weeklyRevenues[$currentWeekStart->format('W')] = $revenue;

            $currentWeekStart = (clone $currentWeekEnd)->modify('+1 day');
        }

        return $this->transformToWeeklyRevenueList($weeklyRevenues);
    }

    // Méthode de calcul pour les revenus mensuels de l'année en cours
    public function getMonthlyRevenueForCurrentYear(?int $orderSource = null): array
    {
        $now = new DateTime();
        $startOfYear = (clone $now)->modify('first day of January');
        $monthlyRevenues = [];

        for ($i = 0; $i < 12; $i++) {
            $currentMonthStart = (clone $startOfYear)->modify("+$i month");
            $currentMonthEnd = (clone $currentMonthStart)->modify('last day of this month');

            if ($currentMonthStart > $now) {
                break; 
            }

            $revenue = $this->orderRepository->getTotalRevenueBetweenDates($currentMonthStart, $currentMonthEnd, $orderSource);
            $monthlyRevenues[$currentMonthStart->format('F')] = $revenue;
        }

        return $this->transformToMonthlyRevenueList($monthlyRevenues);
    }

    // Méthode de calcul pour le revenu de la semaine donnée
    public function getRevenueForWeek(int $weeksAgo, ?int $orderSource = null): float
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week')->modify("-$weeksAgo week");
        $endOfWeek = (clone $startOfWeek)->modify('sunday this week');

        return $this->orderRepository->getTotalRevenueBetweenDates($startOfWeek, $endOfWeek, $orderSource);
    }

    // Méthode de calcul pour le revenu du mois donné
    public function getRevenueForMonth(int $monthsAgo, ?int $orderSource = null): float
    {
        $now = new DateTime();
        $startOfMonth = (clone $now)->modify('first day of this month')->modify("-$monthsAgo month");
        $endOfMonth = (clone $startOfMonth)->modify('last day of this month');

        return $this->orderRepository->getTotalRevenueBetweenDates($startOfMonth, $endOfMonth, $orderSource);
    }

    // Méthode de calcul pour le revenu de l'année donnée
    public function getRevenueForYear(int $yearsAgo, ?int $orderSource = null): float
    {
        $now = new DateTime();
        $startOfYear = (clone $now)->modify('first day of January')->modify("-$yearsAgo year");
        $endOfYear = (clone $startOfYear)->modify('last day of December');

        return $this->orderRepository->getTotalRevenueBetweenDates($startOfYear, $endOfYear);
    }

    // Méthode de calcul pour un intervalle personnalisé
    public function getRevenueForCustomInterval(DateTime $startDate, DateTime $endDate, ?int $orderSource = null): float
    {
        return $this->orderRepository->getTotalRevenueBetweenDates($startDate, $endDate, $orderSource);
    }

    // Méthodes de transformation en listes typées
    private function transformToDailyRevenueList(array $dailyData): array
    {
        $dailyRevenueList = [];
        foreach ($dailyData as $date => $revenue) {
            $dailyRevenueList[] = [
                'date' => $date,
                'revenue' => $revenue,
            ];
        }
        return $dailyRevenueList;
    }

    private function transformToWeeklyRevenueList(array $weeklyData): array
    {
        $weeklyRevenueList = [];
        foreach ($weeklyData as $week => $revenue) {
            $weeklyRevenueList[] = [
                'week' => $week,
                'revenue' => $revenue,
            ];
        }
        return $weeklyRevenueList;
    }

    private function transformToMonthlyRevenueList(array $monthlyData): array
    {
        $monthlyRevenueList = [];
        foreach ($monthlyData as $month => $revenue) {
            $monthlyRevenueList[] = [
                'month' => $month,
                'revenue' => $revenue,
            ];
        }
        return $monthlyRevenueList;
    }
}
