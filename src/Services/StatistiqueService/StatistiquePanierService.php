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
    public function getAverageOrderValueForCurrentWeek(): float
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week')->setTime(0, 0, 0);
        $endOfWeek = (clone $startOfWeek)->modify('+6 days')->setTime(23, 59, 59);

        return $this->orderRepository->getAverageOrderValueBetweenDates($startOfWeek, $endOfWeek);
    }

    // Méthode pour obtenir le panier moyen pour la semaine précédente
    public function getAverageOrderValueForLastWeek(): float
    {
        $now = new DateTime();
        $startOfLastWeek = (clone $now)->modify('monday last week')->setTime(0, 0, 0);
        $endOfLastWeek = (clone $startOfLastWeek)->modify('+6 days')->setTime(23, 59, 59);

        return $this->orderRepository->getAverageOrderValueBetweenDates($startOfLastWeek, $endOfLastWeek);
    }

    // Méthode pour obtenir le panier moyen quotidien pour la semaine en cours
    public function getDailyAverageOrderValueForCurrentWeek(): array
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week');
        $dailyAverages = [];

        for ($i = 0; $i < 7; $i++) {
            $currentDayStart = (clone $startOfWeek)->modify("+$i day")->setTime(0, 0, 0);
            $currentDayEnd = (clone $currentDayStart)->setTime(23, 59, 59);

            $average = $this->orderRepository->getAverageOrderValueBetweenDates($currentDayStart, $currentDayEnd);
            $dailyAverages[$currentDayStart->format('Y-m-d')] = $average;
        }

        return $dailyAverages;
    }

    // Méthode pour obtenir le panier moyen pour le mois en cours
    public function getAverageOrderValueForCurrentMonth(): float
    {
        $now = new DateTime();
        $startOfMonth = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
        $endOfMonth = (clone $now)->modify('last day of this month')->setTime(23, 59, 59);

        return $this->orderRepository->getAverageOrderValueBetweenDates($startOfMonth, $endOfMonth);
    }

    // Méthode pour obtenir le panier moyen pour le mois précédent
    public function getAverageOrderValueForLastMonth(): float
    {
        $now = new DateTime();
        $startOfLastMonth = (clone $now)->modify('first day of previous month')->setTime(0, 0, 0);
        $endOfLastMonth = (clone $startOfLastMonth)->modify('last day of this month')->setTime(23, 59, 59);

        return $this->orderRepository->getAverageOrderValueBetweenDates($startOfLastMonth, $endOfLastMonth);
    }

    // Méthode pour obtenir le panier moyen hebdomadaire pour le mois en cours
    public function getWeeklyAverageOrderValueForCurrentMonth(): array
    {
        $now = new DateTime();
        $startOfMonth = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
        $weeklyAverages = [];
        $currentWeekStart = clone $startOfMonth;

        while ($currentWeekStart->format('m') == $now->format('m')) {
            $currentWeekEnd = (clone $currentWeekStart)->modify('+6 days')->setTime(23, 59, 59);

            // Si la fin de la semaine dépasse le mois en cours, ajustez-la à la fin du mois
            if ($currentWeekEnd->format('m') != $now->format('m')) {
                $currentWeekEnd = (clone $now)->modify('last day of this month')->setTime(23, 59, 59);
            }

            $average = $this->orderRepository->getAverageOrderValueBetweenDates($currentWeekStart, $currentWeekEnd);
            $weeklyAverages[$currentWeekStart->format('W')] = $average;

            $currentWeekStart = (clone $currentWeekEnd)->modify('+1 day')->setTime(0, 0, 0);
        }

        return $weeklyAverages;
    }

    // Méthode pour obtenir le panier moyen annuel pour l'année en cours
    public function getAverageOrderValueForCurrentYear(): float
    {
        $now = new DateTime();
        $startOfYear = (clone $now)->modify('first day of January')->setTime(0, 0, 0);
        $endOfYear = (clone $startOfYear)->modify('last day of December')->setTime(23, 59, 59);

        return $this->orderRepository->getAverageOrderValueBetweenDates($startOfYear, $endOfYear);
    }

    // Méthode pour obtenir le panier moyen mensuel pour l'année en cours
    public function getMonthlyAverageOrderValueForCurrentYear(): array
    {
        $now = new DateTime();
        $startOfYear = (clone $now)->modify('first day of January')->setTime(0, 0, 0);
        $monthlyAverages = [];

        for ($i = 0; $i < 12; $i++) {
            $currentMonthStart = (clone $startOfYear)->modify("+$i month")->setTime(0, 0, 0);
            $currentMonthEnd = (clone $currentMonthStart)->modify('last day of this month')->setTime(23, 59, 59);

            if ($currentMonthStart > $now) {
                break; // Arrêter si le mois dépasse la date actuelle
            }

            $average = $this->orderRepository->getAverageOrderValueBetweenDates($currentMonthStart, $currentMonthEnd);
            $monthlyAverages[$currentMonthStart->format('F')] = $average;
        }

        return $monthlyAverages;
    }
}
