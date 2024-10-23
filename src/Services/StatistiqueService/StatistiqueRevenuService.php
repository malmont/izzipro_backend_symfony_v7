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


    public function getDailyRevenueForCurrentWeek(): array
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week');
        $dailyRevenues = [];

        for ($i = 0; $i < 7; $i++) {
            $currentDay = (clone $startOfWeek)->modify("+$i day");
            $nextDay = (clone $currentDay)->modify('+1 day');

            $revenue = $this->orderRepository->getTotalRevenueBetweenDates($currentDay, $nextDay);
            $dailyRevenues[$currentDay->format('Y-m-d')] = $revenue;
        }

        return $dailyRevenues;
    }


    public function getWeeklyRevenueForCurrentMonth(): array
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

            $revenue = $this->orderRepository->getTotalRevenueBetweenDates($currentWeekStart, $currentWeekEnd);
            $weeklyRevenues[$currentWeekStart->format('W')] = $revenue;

            $currentWeekStart = (clone $currentWeekEnd)->modify('+1 day');
        }

        return $weeklyRevenues;
    }


    public function getMonthlyRevenueForCurrentYear(): array
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

            $revenue = $this->orderRepository->getTotalRevenueBetweenDates($currentMonthStart, $currentMonthEnd);
            $monthlyRevenues[$currentMonthStart->format('F')] = $revenue;
        }

        return $monthlyRevenues;
    }


    public function getRevenueForWeek(int $weeksAgo): float
    {
        $now = new DateTime();
        $startOfWeek = (clone $now)->modify('monday this week')->modify("-$weeksAgo week");
        $endOfWeek = (clone $startOfWeek)->modify('sunday this week');

        return $this->orderRepository->getTotalRevenueBetweenDates($startOfWeek, $endOfWeek);
    }


    public function getRevenueForMonth(int $monthsAgo): float
    {
        $now = new DateTime();
        $startOfMonth = (clone $now)->modify('first day of this month')->modify("-$monthsAgo month");
        $endOfMonth = (clone $startOfMonth)->modify('last day of this month');

        return $this->orderRepository->getTotalRevenueBetweenDates($startOfMonth, $endOfMonth);
    }

    public function getRevenueForYear(int $yearsAgo): float
    {
        $now = new DateTime();
        $startOfYear = (clone $now)->modify('first day of January')->modify("-$yearsAgo year");
        $endOfYear = (clone $startOfYear)->modify('last day of December');

        return $this->orderRepository->getTotalRevenueBetweenDates($startOfYear, $endOfYear);
    }
    public function getRevenueForCustomInterval(DateTime $startDate, DateTime $endDate): float
    {
        return $this->orderRepository->getTotalRevenueBetweenDates($startDate, $endDate);
    }
}
