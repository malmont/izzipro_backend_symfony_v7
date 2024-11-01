<?php

namespace App\Services\StatistiqueService;

use App\Repository\OrderRepository;
use DateTime;

class StatistiquePanierService
{
    private $orderRepository;

    public function __construct(OrderRepository $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }

    // Méthode pour obtenir le panier moyen pour la semaine en cours
    public function getAverageOrderValueForCurrentWeek(?int $orderSource = null): float
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week')->setTime(0, 0, 0);
        $endOfWeek = (clone $startOfWeek)->modify('+6 days')->setTime(23, 59, 59);

        return $this->orderRepository->getAverageOrderValueBetweenDates($startOfWeek, $endOfWeek, $orderSource);
    }

    // Méthode pour obtenir le panier moyen pour la semaine précédente
    public function getAverageOrderValueForLastWeek(?int $orderSource = null): float
    {
        $now = new DateTime();
        $startOfLastWeek = (clone $now)->modify('monday last week')->setTime(0, 0, 0);
        $endOfLastWeek = (clone $startOfLastWeek)->modify('+6 days')->setTime(23, 59, 59);

        return $this->orderRepository->getAverageOrderValueBetweenDates($startOfLastWeek, $endOfLastWeek, $orderSource);
    }

    // Méthode pour obtenir le panier moyen quotidien pour la semaine en cours
    public function getDailyAverageOrderValueForCurrentWeek(?int $orderSource = null): array
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week');
        $dailyAverages = [];

        for ($i = 0; $i < 7; $i++) {
            $currentDayStart = (clone $startOfWeek)->modify("+$i day")->setTime(0, 0, 0);
            $currentDayEnd = (clone $currentDayStart)->setTime(23, 59, 59);

            $average = $this->orderRepository->getAverageOrderValueBetweenDates($currentDayStart, $currentDayEnd, $orderSource);
            $dailyAverages[$currentDayStart->format('Y-m-d')] = $average;
        }

        return $this->transformToDailyAverageList($dailyAverages);
    }

    // Méthode pour obtenir le panier moyen pour le mois en cours
    public function getAverageOrderValueForCurrentMonth(?int $orderSource = null): float
    {
        $now = new DateTime();
        $startOfMonth = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
        $endOfMonth = (clone $now)->modify('last day of this month')->setTime(23, 59, 59);

        return $this->orderRepository->getAverageOrderValueBetweenDates($startOfMonth, $endOfMonth, $orderSource);
    }

    // Méthode pour obtenir le panier moyen pour le mois précédent
    public function getAverageOrderValueForLastMonth(?int $orderSource = null): float
    {
        $now = new DateTime();
        $startOfLastMonth = (clone $now)->modify('first day of previous month')->setTime(0, 0, 0);
        $endOfLastMonth = (clone $startOfLastMonth)->modify('last day of this month')->setTime(23, 59, 59);

        return $this->orderRepository->getAverageOrderValueBetweenDates($startOfLastMonth, $endOfLastMonth, $orderSource);
    }

    // Méthode pour obtenir le panier moyen hebdomadaire pour le mois en cours
    public function getWeeklyAverageOrderValueForCurrentMonth(?int $orderSource = null): array
    {
        $now = new DateTime();
        $startOfMonth = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
        $weeklyAverages = [];
        $currentWeekStart = clone $startOfMonth;

        while ($currentWeekStart->format('m') == $now->format('m')) {
            $currentWeekEnd = (clone $currentWeekStart)->modify('+6 days')->setTime(23, 59, 59);

            if ($currentWeekEnd->format('m') != $now->format('m')) {
                $currentWeekEnd = (clone $now)->modify('last day of this month')->setTime(23, 59, 59);
            }

            $average = $this->orderRepository->getAverageOrderValueBetweenDates($currentWeekStart, $currentWeekEnd, $orderSource);
            $weeklyAverages[$currentWeekStart->format('W')] = $average;

            $currentWeekStart = (clone $currentWeekEnd)->modify('+1 day')->setTime(0, 0, 0);
        }

        return $this->transformToWeeklyAverageList($weeklyAverages);
    }

    // Méthode pour obtenir le panier moyen annuel pour l'année en cours
    public function getAverageOrderValueForCurrentYear(?int $orderSource = null): float
    {
        $now = new DateTime();
        $startOfYear = (clone $now)->modify('first day of January')->setTime(0, 0, 0);
        $endOfYear = (clone $startOfYear)->modify('last day of December')->setTime(23, 59, 59);

        return $this->orderRepository->getAverageOrderValueBetweenDates($startOfYear, $endOfYear, $orderSource);
    }

    // Méthode pour obtenir le panier moyen mensuel pour l'année en cours
    public function getMonthlyAverageOrderValueForCurrentYear(?int $orderSource = null): array
    {
        $now = new DateTime();
        $startOfYear = (clone $now)->modify('first day of January')->setTime(0, 0, 0);
        $monthlyAverages = [];

        for ($i = 0; $i < 12; $i++) {
            $currentMonthStart = (clone $startOfYear)->modify("+$i month")->setTime(0, 0, 0);
            $currentMonthEnd = (clone $currentMonthStart)->modify('last day of this month')->setTime(23, 59, 59);

            if ($currentMonthStart > $now) {
                break;
            }

            $average = $this->orderRepository->getAverageOrderValueBetweenDates($currentMonthStart, $currentMonthEnd, $orderSource);
            $monthlyAverages[$currentMonthStart->format('F')] = $average;
        }

        return $this->transformToMonthlyAverageList($monthlyAverages);
    }

    // Méthodes de transformation pour formater les listes
    private function transformToDailyAverageList(array $dailyData): array
    {
        $dailyAverageList = [];
        foreach ($dailyData as $date => $average) {
            $dailyAverageList[] = [
                'date' => $date,
                'averageOrderValue' => $average,
            ];
        }
        return $dailyAverageList;
    }

    private function transformToWeeklyAverageList(array $weeklyData): array
    {
        $weeklyAverageList = [];
        foreach ($weeklyData as $week => $average) {
            $weeklyAverageList[] = [
                'week' => $week,
                'averageOrderValue' => $average,
            ];
        }
        return $weeklyAverageList;
    }

    private function transformToMonthlyAverageList(array $monthlyData): array
    {
        $monthlyAverageList = [];
        foreach ($monthlyData as $month => $average) {
            $monthlyAverageList[] = [
                'month' => $month,
                'averageOrderValue' => $average,
            ];
        }
        return $monthlyAverageList;
    }
}
