<?php
namespace App\Services\StockEvolutionService;

use App\Entity\InventoryMovements;
use App\Repository\InventoryMovementsRepository;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;

/**
 * GET /api/stock-evolution : quantités par type de mouvement de stock sur la semaine, le mois et l'année en cours.
 * Correctif B2 (11/11/2026, `docs/gestion-mise-a-niveau.md`) : bornes en **jours du magasin** (`StoreClock`, semaine du lundi 0 h au dimanche
 * inclus, mois et année civils, fin exclusive ; avant : heure courante et fuseau du serveur, d'où des mouvements du début et de la fin
 * manqués) ; `code` stable sur chaque élément (additif) ; 5e élément `reception` (type 5, réception fournisseur du lot R1). Les quatre
 * premiers éléments gardent leur ordre et leur `type` historique ; le type 3 « Return » n'est écrit par aucun chemin aujourd'hui (0).
 */
class StockEvolutionService
{
    /** movement_type_id => [libellé historique, code stable] */
    public const TYPES = [
        1 => ['Entrant', 'in'],
        2 => ['Sortant', 'out'],
        3 => ['Return', 'return'],
        4 => ['Ajustement', 'adjustment'],
        5 => ['Réception fournisseur', 'reception'],
    ];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly StoreClock $clock)
    {
    }

    public function getStockEvolutionForCurrentWeek(): array
    {
        $start = $this->now()->modify('monday this week')->setTime(0, 0);

        return $this->between($start, $start->modify('+7 days'));
    }

    public function getStockEvolutionForCurrentMonth(): array
    {
        $start = $this->now()->modify('first day of this month')->setTime(0, 0);

        return $this->between($start, $start->modify('+1 month'));
    }

    public function getStockEvolutionForCurrentYear(): array
    {
        $now = $this->now();
        $start = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0);

        return $this->between($start, $start->modify('+1 year'));
    }

    /** @return list<array{type: string, code: string, quantity: int}> */
    private function between(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $utc = new \DateTimeZone('UTC');
        $totals = $this->repository()->totalsByMovementTypeId($from->setTimezone($utc), $to->setTimezone($utc));
        $out = [];
        foreach (self::TYPES as $id => [$type, $code]) {
            $out[] = ['type' => $type, 'quantity' => $totals[$id] ?? 0, 'code' => $code];
        }

        return $out;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->clock->timezone());
    }

    private function repository(): InventoryMovementsRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(InventoryMovements::class);
    }
}
