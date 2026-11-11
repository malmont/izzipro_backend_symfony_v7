<?php

namespace App\Gestion\Services;

use App\Services\StockEvolutionService\StockValueService;
use App\Services\TenantEntityManagerProvider;

/**
 * Valeur du stock et instantané quotidien (lot S1, 11/11/2026, `docs/statistiques-gestion.md` § 4). Valeur de vente : règle de
 * `stock_value_cents` (B1, par variante) ; valeur au coût connu : stock × coût courant (lignes d'achat L0, sinon prix d'achat), comme le
 * simulateur. Un instantané par jour du magasin, pris par le passage quotidien de 10 h, jamais remplacé (rejouer le passage ne change rien).
 */
class GestionStockSnapshotService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly StockValueService $stockValue,
        private readonly GestionSimulationService $simulation,
    ) {
    }

    /** @return array{saleValue: int, costValue: int} valeur du stock maintenant */
    public function current(\DateTimeImmutable $today): array
    {
        $cost = 0.0;
        foreach ($this->simulation->stockByVariant($today->format('Y-m-d')) as $s) {
            if ($s['cost'] !== null) {
                $cost += $s['stock'] * $s['cost'];
            }
        }

        return ['saleValue' => $this->stockValue->stockValueCents(), 'costValue' => (int) round($cost)];
    }

    /** Instantané du jour (sans effet s'il existe déjà) ; rend vrai s'il vient d'être pris */
    public function take(\DateTimeImmutable $today): bool
    {
        $v = $this->current($today);

        return $this->conn()->executeStatement(
            'INSERT INTO gestion_stock_snapshot (snapshot_date, sale_value_cents, cost_value_cents, created_at) VALUES (?, ?, ?, NOW()) ON CONFLICT (snapshot_date) DO NOTHING',
            [$today->format('Y-m-d'), $v['saleValue'], $v['costValue']]
        ) > 0;
    }

    /** @return array{date: string, saleValue: int, costValue: int}|null instantané d'un jour, ou null */
    public function at(string $day): ?array
    {
        $row = $this->conn()->fetchAssociative('SELECT * FROM gestion_stock_snapshot WHERE snapshot_date = ?', [$day]);

        return $row === false ? null : ['date' => substr((string) $row['snapshot_date'], 0, 10), 'saleValue' => (int) $row['sale_value_cents'], 'costValue' => (int) $row['cost_value_cents']];
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
