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
    public function getOrderCountForCurrentWeek(int $typeId, int $statusId): int
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week');
        $endOfWeek = (clone $startOfWeek)->modify('sunday this week');

        return $this->orderRepository->getOrderCountBetweenDatesAndFilters($startOfWeek, $endOfWeek, $typeId, $statusId);
    }

    // Méthode pour obtenir le nombre de commandes pour chaque jour de la semaine en cours, avec filtres
    public function getDailyOrderCountForCurrentWeek(int $typeId, int $statusId): array
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week');
        $dailyCounts = [];

        for ($i = 0; $i < 7; $i++) {
            $currentDay = (clone $startOfWeek)->modify("+$i day");
            $startOfDay = (clone $currentDay)->setTime(0, 0, 0);
            $endOfDay = (clone $currentDay)->setTime(23, 59, 59);

            // Récupérer le nombre de commandes pour le jour actuel
            $count = $this->orderRepository->getOrderCountBetweenDatesAndFilters($startOfDay, $endOfDay, $typeId, $statusId);
            $dailyCounts[$currentDay->format('Y-m-d')] = $count;
        }

        return $dailyCounts;
    }


    // Méthode pour obtenir le nombre de commandes pour le mois en cours
    public function getOrderCountForCurrentMonth(): int
    {
        $now = new DateTime();
        $startOfMonth = (clone $now)->modify('first day of this month');
        $endOfMonth = (clone $startOfMonth)->modify('last day of this month');

        return $this->orderRepository->getOrderCountBetweenDates($startOfMonth, $endOfMonth);
    }

    // Méthode pour obtenir le nombre de commandes pour chaque semaine du mois en cours
    public function getWeeklyOrderCountForCurrentMonth(): array
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

            $count = $this->orderRepository->getOrderCountBetweenDates($currentWeekStart, $currentWeekEnd);
            $weeklyCounts[$currentWeekStart->format('W')] = $count;

            $currentWeekStart = (clone $currentWeekEnd)->modify('+1 day');
        }

        return $weeklyCounts;
    }

    // Méthode pour obtenir le nombre de commandes pour l'année en cours
    public function getOrderCountForCurrentYear(): int
    {
        $now = new DateTime();
        $startOfYear = (clone $now)->modify('first day of January');
        $endOfYear = (clone $startOfYear)->modify('last day of December');

        return $this->orderRepository->getOrderCountBetweenDates($startOfYear, $endOfYear);
    }

    // Méthode pour obtenir le nombre de commandes pour chaque mois de l'année en cours
    public function getMonthlyOrderCountForCurrentYear(): array
    {
        $now = new DateTime();
        $startOfYear = (clone $now)->modify('first day of January');
        $monthlyCounts = [];

        for ($i = 0; $i < 12; $i++) {
            $currentMonthStart = (clone $startOfYear)->modify("+$i month");
            $currentMonthEnd = (clone $currentMonthStart)->modify('last day of this month');

            if ($currentMonthStart > $now) {
                break; // Arrêter si le mois dépasse la date actuelle
            }

            $count = $this->orderRepository->getOrderCountBetweenDates($currentMonthStart, $currentMonthEnd);
            $monthlyCounts[$currentMonthStart->format('F')] = $count;
        }

        return $monthlyCounts;
    }

    // Méthode pour obtenir le nombre de commandes pour le mois précédent de l'année en cours
    public function getOrderCountForLastMonth(): int
    {
        $now = new DateTime();
        $startOfLastMonth = (clone $now)->modify('first day of previous month');
        $endOfLastMonth = (clone $startOfLastMonth)->modify('last day of this month');

        return $this->orderRepository->getOrderCountBetweenDates($startOfLastMonth, $endOfLastMonth);
    }
}
