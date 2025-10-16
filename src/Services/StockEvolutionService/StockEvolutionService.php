<?php
namespace App\Services\StockEvolutionService;

use App\Entity\InventoryMovements;
use App\Repository\InventoryMovementsRepository;
use App\Services\TenantEntityManagerProvider;
use DateTime;

class StockEvolutionService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }


    private function getInventoryMovementsRepository(): InventoryMovementsRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(InventoryMovements::class);
    }

    public function getStockEvolutionForCurrentWeek(): array
    {
        $startOfWeek = (new DateTime())->modify('monday this week');
        $endOfWeek = (new DateTime())->modify('sunday this week');

        return $this->transformToStockEvolutionList(
            $this->getStockEvolutionBetweenDates($startOfWeek, $endOfWeek)
        );
    }

    public function getStockEvolutionForCurrentMonth(): array
    {
        $startOfMonth = (new DateTime())->modify('first day of this month');
        $endOfMonth = (new DateTime())->modify('last day of this month');

        return $this->transformToStockEvolutionList(
            $this->getStockEvolutionBetweenDates($startOfMonth, $endOfMonth)
        );
    }

    public function getStockEvolutionForCurrentYear(): array
    {
        $startOfYear = (new DateTime())->modify('first day of January');
        $endOfYear = (new DateTime())->modify('last day of December');

        return $this->transformToStockEvolutionList(
            $this->getStockEvolutionBetweenDates($startOfYear, $endOfYear)
        );
    }

    private function getStockEvolutionBetweenDates(DateTime $startDate, DateTime $endDate): array
    {
        // MODIFICATION 3 : On utilise notre nouvelle méthode privée
        $inventoryMovementsRepository = $this->getInventoryMovementsRepository();
        
        // On utilise la variable locale $inventoryMovementsRepository
        $entrant = $inventoryMovementsRepository->getTotalQuantityByMovementType($startDate, $endDate, 'Entrant');
        $sortant = $inventoryMovementsRepository->getTotalQuantityByMovementType($startDate, $endDate, 'Sortant');
        $return = $inventoryMovementsRepository->getTotalQuantityByMovementType($startDate, $endDate, 'Return');
        $ajustement = $inventoryMovementsRepository->getTotalQuantityByMovementType($startDate, $endDate, 'Ajustement');

        return [
            'Entrant' => $entrant,
            'Sortant' => $sortant,
            'Return' => $return,
            'Ajustement' => $ajustement,
        ];
    }

    /**
     * INCHANGÉ : Cette méthode privée est une logique pure, pas de modification nécessaire.
     */
    private function transformToStockEvolutionList(array $stockData): array
    {
        $stockEvolutionList = [];
        foreach ($stockData as $type => $quantity) {
            $stockEvolutionList[] = [
                'type' => $type,
                'quantity' => $quantity,
            ];
        }
        return $stockEvolutionList;
    }
}