<?php

namespace App\Services\GestionService;

use App\Services\TenantEntityManagerProvider;

/**
 * Chiffres des achats (lot G2, 09/11/2026, `docs/gestion-mise-a-niveau.md` § 3) : un seul calcul pour le tableau de bord
 * d'une commande fournisseur, d'une collection et du combiné, par agrégats SQL (une requête par tableau, plus de parcours
 * des entités ni de chargement paresseux commande → produits). Montants en cents entiers, taux en pourcentage arrondis à
 * deux décimales, coefficient moyen pondéré par les quantités.
 *
 * Coût d'achat unitaire d'un produit : `commande_cost_cents ?? purchase_price ?? price` (règle du 09/11/2026, décision du
 * propriétaire, même règle que `Product::commandeUnitCost()`).
 *   purchaseCost (= totalItemCost) = Σ coût × quantité, dans la devise du site (coût et frais de port × taux de la commande, lot G6)
 *   stockValue                     = Σ coût × quantité × coefficient (valeur de vente attendue du stock acheté)
 *   usedBudget                     = purchaseCost + frais de port (+ notes de frais pour une collection)
 *   marge                          = stockValue − usedBudget ; tauxMarge = marge / usedBudget ; tauxMarque = marge / stockValue (définitions usuelles, lot P1)
 */
class PurchaseMetricsCalculator
{
    /** Coût unitaire ramené dans la devise du site : coût de la commande (devise d'achat) × taux (lot G6, 10/11/2026) */
    private const UNIT_COST = 'COALESCE(p.commande_cost_cents, p.purchase_price, p.price, 0) * COALESCE(c.exchange_rate, 1)';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return array{purchaseCost: int, stockValue: int, itemCount: int, modelCount: int, averageMultiplier: float, shippingCost: int} */
    public function commandeTotals(int $commandeId): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT ' . self::sums() . ', (SELECT COALESCE(SUM(f.price * COALESCE(c2.exchange_rate, 1)), 0) FROM frais_de_port f JOIN commande c2 ON c2.id = f.commande_id WHERE f.commande_id = :id) AS shipping_cost' .
            ' FROM product p JOIN commande c ON c.id = p.commande_id WHERE p.commande_id = :id',
            ['id' => $commandeId]
        ) ?: [];

        return self::totals($row) + ['shippingCost' => (int) ($row['shipping_cost'] ?? 0)];
    }

    /** @return array{purchaseCost: int, stockValue: int, itemCount: int, modelCount: int, averageMultiplier: float, shippingCost: int, expenseCost: int, orderCount: int} */
    public function collectionTotals(int $collectionId): array
    {
        return $this->collectionsTotals('c.collections_id = :id', ['id' => $collectionId]);
    }

    /** Période (lot P1, 10/11/2026) : commandes datées dans [start, end] quelle que soit leur collection, notes de frais datées dans la période */
    public function periodTotals(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $params = ['from' => $start->format('Y-m-d 00:00:00'), 'to' => \DateTimeImmutable::createFromInterface($end)->modify('+1 day')->format('Y-m-d 00:00:00')];
        $connection = $this->connection();
        $row = $connection->fetchAssociative('SELECT ' . self::sums() . ' FROM product p JOIN commande c ON c.id = p.commande_id WHERE c.date >= :from AND c.date < :to', $params) ?: [];
        $costs = $connection->fetchAssociative(
            'SELECT (SELECT COALESCE(SUM(f.price * COALESCE(c.exchange_rate, 1)), 0) FROM frais_de_port f JOIN commande c ON c.id = f.commande_id WHERE c.date >= :from AND c.date < :to) AS shipping_cost,' .
            ' (SELECT COUNT(*) FROM commande c WHERE c.date >= :from AND c.date < :to) AS order_count,' .
            ' (SELECT COALESCE(SUM(n.montant), 0) FROM note_de_frais n WHERE n.date >= :from AND n.date < :to) AS expense_cost',
            $params
        ) ?: [];

        return self::totals($row) + ['shippingCost' => (int) ($costs['shipping_cost'] ?? 0), 'expenseCost' => (int) ($costs['expense_cost'] ?? 0), 'orderCount' => (int) ($costs['order_count'] ?? 0)];
    }

    /** Toutes les périodes du calendrier (tableau combiné `kind=period`) : union de leurs plages de dates = commandes et notes datées dans une période existante */
    public function allPeriodsTotals(): array
    {
        $connection = $this->connection();
        $in = "EXISTS (SELECT 1 FROM collections pc WHERE pc.kind = 'period' AND pc.del = false AND pc.start_date_collection <= c.date AND c.date < pc.end_date_collection + INTERVAL '1 day')";
        $row = $connection->fetchAssociative('SELECT ' . self::sums() . ' FROM product p JOIN commande c ON c.id = p.commande_id WHERE ' . $in) ?: [];
        $costs = $connection->fetchAssociative(
            'SELECT (SELECT COALESCE(SUM(f.price * COALESCE(c.exchange_rate, 1)), 0) FROM frais_de_port f JOIN commande c ON c.id = f.commande_id WHERE ' . $in . ') AS shipping_cost,' .
            ' (SELECT COUNT(*) FROM commande c WHERE ' . $in . ') AS order_count,' .
            " (SELECT COALESCE(SUM(n.montant), 0) FROM note_de_frais n WHERE EXISTS (SELECT 1 FROM collections pc WHERE pc.kind = 'period' AND pc.del = false AND pc.start_date_collection <= n.date AND n.date <= pc.end_date_collection)) AS expense_cost"
        ) ?: [];

        return self::totals($row) + ['shippingCost' => (int) ($costs['shipping_cost'] ?? 0), 'expenseCost' => (int) ($costs['expense_cost'] ?? 0), 'orderCount' => (int) ($costs['order_count'] ?? 0)];
    }

    /** Toutes les collections non supprimées (tableau combiné) */
    public function allCollectionsTotals(): array
    {
        return $this->collectionsTotals("c.collections_id IN (SELECT id FROM collections WHERE del = false AND kind = 'manual')", []); // manuelles seulement : les périodes ont leur propre combiné (lot P1, 10/11/2026)
    }

    /**
     * Blocs communs du tableau de bord à partir des totaux et du budget (cents) : BudgetGeneral, ValeurStock, TauxMarge, purchaseCost.
     *
     * @param array{purchaseCost: int, stockValue: int, averageMultiplier: float} $totals
     * @return array<string, mixed>
     */
    public static function blocks(array $totals, int $generalBudget, int $usedBudget): array
    {
        $stockValue = $totals['stockValue'];
        $purchaseCost = $totals['purchaseCost'];
        $margin = $stockValue - $usedBudget;

        return [
            'averageMultiplier' => $totals['averageMultiplier'],
            'purchaseCost' => $purchaseCost,
            'BudgetGeneral' => ['generalBudget' => $generalBudget, 'usedBudget' => $usedBudget, 'remainingBudget' => $generalBudget - $usedBudget],
            'ValeurStock' => ['stockValue' => $stockValue, 'marge' => $margin],
            'TauxMarge' => [
                // définitions usuelles depuis le lot P1 (10/11/2026 ; inversées avant) : taux de marge = marge / coût total (achats + frais), taux de marque = marge / valeur de vente
                'tauxMarge' => $usedBudget > 0 ? round($margin / $usedBudget * 100, 2) : 0.0,
                'tauxMarque' => $stockValue > 0 ? round($margin / $stockValue * 100, 2) : 0.0,
            ],
        ];
    }

    private function collectionsTotals(string $where, array $params): array
    {
        $connection = $this->connection();
        $row = $connection->fetchAssociative(
            'SELECT ' . self::sums() . ' FROM product p JOIN commande c ON c.id = p.commande_id WHERE ' . $where,
            $params
        ) ?: [];
        $costs = $connection->fetchAssociative(
            'SELECT (SELECT COALESCE(SUM(f.price * COALESCE(c.exchange_rate, 1)), 0) FROM frais_de_port f JOIN commande c ON c.id = f.commande_id WHERE ' . $where . ') AS shipping_cost,' .
            ' (SELECT COUNT(*) FROM commande c WHERE ' . $where . ') AS order_count,' .
            ' (SELECT COALESCE(SUM(n.montant), 0) FROM note_de_frais n WHERE n.collection_id ' . ($params === [] ? 'IN (SELECT id FROM collections WHERE del = false)' : '= :id') . ') AS expense_cost',
            $params
        ) ?: [];

        return self::totals($row) + [
            'shippingCost' => (int) ($costs['shipping_cost'] ?? 0),
            'expenseCost' => (int) ($costs['expense_cost'] ?? 0),
            'orderCount' => (int) ($costs['order_count'] ?? 0),
        ];
    }

    private static function sums(): string
    {
        $cost = self::UNIT_COST;
        $qty = 'COALESCE(p.quantity, 0)';
        $coef = 'COALESCE(p.coefficient_multiplier, 0)';

        return "COALESCE(SUM($cost * $qty), 0) AS purchase_cost, COALESCE(SUM($cost * $qty * $coef), 0) AS stock_value, COALESCE(SUM($qty), 0) AS item_count, COUNT(p.id) AS model_count, COALESCE(SUM($coef * $qty), 0) AS multiplier_sum";
    }

    /** @param array<string, mixed> $row */
    private static function totals(array $row): array
    {
        $itemCount = (int) ($row['item_count'] ?? 0);

        return [
            'purchaseCost' => (int) round((float) ($row['purchase_cost'] ?? 0)),
            'stockValue' => (int) round((float) ($row['stock_value'] ?? 0)),
            'itemCount' => $itemCount,
            'modelCount' => (int) ($row['model_count'] ?? 0),
            'averageMultiplier' => $itemCount > 0 ? round((float) $row['multiplier_sum'] / $itemCount, 2) : 0.0,
        ];
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
