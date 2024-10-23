<?php

namespace App\Services\StockEvolutionService;

use App\Repository\InventoryMovementsRepository;
use DateTime;


class StockEvolutionService
{
    private $inventoryMovementsRepository;

    public function __construct(InventoryMovementsRepository $inventoryMovementsRepository)
    {
        $this->inventoryMovementsRepository = $inventoryMovementsRepository;
    }

    public function getStockEvolutionForCurrentWeek(): array
    {
        $startOfWeek = (new DateTime())->modify('monday this week');
        $endOfWeek = (new DateTime())->modify('sunday this week');

        return $this->getStockEvolutionBetweenDates($startOfWeek, $endOfWeek);
    }

    public function getStockEvolutionForCurrentMonth(): array
    {
        $startOfMonth = (new DateTime())->modify('first day of this month');
        $endOfMonth = (new DateTime())->modify('last day of this month');

        return $this->getStockEvolutionBetweenDates($startOfMonth, $endOfMonth);
    }

    public function getStockEvolutionForCurrentYear(): array
    {
        $startOfYear = (new DateTime())->modify('first day of January');
        $endOfYear = (new DateTime())->modify('last day of December');

        return $this->getStockEvolutionBetweenDates($startOfYear, $endOfYear);
    }

    private function getStockEvolutionBetweenDates(DateTime $startDate, DateTime $endDate): array
    {
        // Calculer les quantités pour chaque type de mouvement
        $entrant = $this->inventoryMovementsRepository->getTotalQuantityByMovementType($startDate, $endDate, 'Entrant');
        $sortant = $this->inventoryMovementsRepository->getTotalQuantityByMovementType($startDate, $endDate, 'Sortant');
        $return = $this->inventoryMovementsRepository->getTotalQuantityByMovementType($startDate, $endDate, 'Return');
        $ajustement = $this->inventoryMovementsRepository->getTotalQuantityByMovementType($startDate, $endDate, 'Ajustement');

        return [
            'Entrant' => $entrant,
            'Sortant' => $sortant,
            'Return' => $return,
            'Ajustement' => $ajustement,
        ];
    }
}