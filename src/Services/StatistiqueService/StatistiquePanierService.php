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
        $startOfWeek = (clone $now)->modify('monday this week');
        $endOfWeek = (clone $startOfWeek)->modify('sunday this week');

        return $this->orderRepository->getAverageOrderValueBetweenDates($startOfWeek, $endOfWeek);
    }

    // Méthode pour obtenir le panier moyen pour la semaine précédente
    public function getAverageOrderValueForLastWeek(): float
    {
        $now = new DateTime();
        $startOfLastWeek = (clone $now)->modify('monday last week');
        $endOfLastWeek = (clone $startOfLastWeek)->modify('sunday this week');

        return $this->orderRepository->getAverageOrderValueBetweenDates($startOfLastWeek, $endOfLastWeek);
    }

    // Méthode pour obtenir le panier moyen quotidien pour la semaine en cours
    public function getDailyAverageOrderValueForCurrentWeek(): array
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week');
        $dailyAverages = [];

        for ($i = 0; $i < 7; $i++) {
            $currentDay = (clone $startOfWeek)->modify("+$i day");
            $nextDay = (clone $currentDay)->modify('+1 day');

            $average = $this->orderRepository->getAverageOrderValueBetweenDates($currentDay, $nextDay);
            $dailyAverages[$currentDay->format('Y-m-d')] = $average;
        }

        return $dailyAverages;
    }

    // Méthode pour obtenir le panier moyen pour le mois en cours
    public function getAverageOrderValueForCurrentMonth(): float
    {
        $now = new DateTime();
        $startOfMonth = (clone $now)->modify('first day of this month');
        $endOfMonth = (clone $startOfMonth)->modify('last day of this month');

        return $this->orderRepository->getAverageOrderValueBetweenDates($startOfMonth, $endOfMonth);
    }

    // Méthode pour obtenir le panier moyen pour le mois précédent
    public function getAverageOrderValueForLastMonth(): float
    {
        $now = new DateTime();
        $startOfLastMonth = (clone $now)->modify('first day of previous month');
        $endOfLastMonth = (clone $startOfLastMonth)->modify('last day of this month');

        return $this->orderRepository->getAverageOrderValueBetweenDates($startOfLastMonth, $endOfLastMonth);
    }

    // Méthode pour obtenir le panier moyen hebdomadaire pour le mois en cours
    public function getWeeklyAverageOrderValueForCurrentMonth(): array
    {
        $now = new DateTime();
        $startOfMonth = (clone $now)->modify('first day of this month');
        $weeklyAverages = [];
        $currentWeekStart = clone $startOfMonth;

        while ($currentWeekStart->format('m') == $now->format('m')) {
            $currentWeekEnd = (clone $currentWeekStart)->modify('sunday this week');
            if ($currentWeekEnd->format('m') != $now->format('m')) {
                $currentWeekEnd = (clone $now)->modify('last day of this month');
            }

            $average = $this->orderRepository->getAverageOrderValueBetweenDates($currentWeekStart, $currentWeekEnd);
            $weeklyAverages[$currentWeekStart->format('W')] = $average;

            $currentWeekStart = (clone $currentWeekEnd)->modify('+1 day');
        }

        return $weeklyAverages;
    }

    // Méthode pour obtenir le panier moyen annuel pour l'année en cours
    public function getAverageOrderValueForCurrentYear(): float
    {
        $now = new DateTime();
        $startOfYear = (clone $now)->modify('first day of January');
        $endOfYear = (clone $startOfYear)->modify('last day of December');

        return $this->orderRepository->getAverageOrderValueBetweenDates($startOfYear, $endOfYear);
    }

    // Méthode pour obtenir le panier moyen mensuel pour l'année en cours
    public function getMonthlyAverageOrderValueForCurrentYear(): array
    {
        $now = new DateTime();
        $startOfYear = (clone $now)->modify('first day of January');
        $monthlyAverages = [];

        for ($i = 0; $i < 12; $i++) {
            $currentMonthStart = (clone $startOfYear)->modify("+$i month");
            $currentMonthEnd = (clone $currentMonthStart)->modify('last day of this month');

            if ($currentMonthStart > $now) {
                break; // Arrêter si le mois dépasse la date actuelle
            }

            $average = $this->orderRepository->getAverageOrderValueBetweenDates($currentMonthStart, $currentMonthEnd);
            $monthlyAverages[$currentMonthStart->format('F')] = $average;
        }

        return $monthlyAverages;
    }
}
