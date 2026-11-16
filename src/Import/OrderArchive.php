<?php

namespace App\Import;

use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;

/**
 * Lot IMP-3 (16/11/2026, `docs/import-boutique.md` § 10) : LECTURE de l'archive des commandes d'avant la migration
 * (`import_order`, `import_order_line`, écrites par `OrderImportService`). C'est le seul lecteur de ces tables.
 *
 * L'archive est À PART de toutes les vraies ventes du site : elle n'est jamais additionnée à elles. Elle sert à trois choses :
 * - la série « ventes d'avant » des statistiques de la gestion (`series`) ;
 * - la comparaison « an dernier » quand le site n'a aucune vraie vente sur cette plage (`dailySales`, mêmes clés que
 *   `PeriodResultCalculator::dailySales`), marquée « avant la migration » par l'appelant ;
 * - la fiche d'un client côté administration (`ordersOf`).
 *
 * Seules les commandes PAYÉES entrent dans les chiffres ; leur montant est hors taxes et hors port, remboursements déduits. L'archive
 * ne connaît ni le coût d'achat (la marge est inconnue), ni le canal, ni les retours article par article.
 */
class OrderArchive
{
    public const PER_PAGE = 20;
    /** Marque de l'origine d'une plage « an dernier » dans les réponses de la gestion */
    public const SOURCE = 'before_migration';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** L'archive du site courant porte-t-elle une commande payée sur ces jours du magasin (inclus, Y-m-d) ? Sans archive : faux */
    public function hasSales(string $fromDay, string $toDay): bool
    {
        try {
            return (bool) $this->conn()->fetchOne("SELECT 1 FROM import_order WHERE state = 'paid' AND day BETWEEN ? AND ? LIMIT 1", [$fromDay, $toDay]);
        } catch (\Throwable) {
            return false; // base pas encore migrée : pas d'archive
        }
    }

    /**
     * Les ventes d'avant par jour du magasin, dans la forme de `PeriodResultCalculator::dailySales` : le chiffre (`sales_amount`), les
     * commandes (`orders_sold`) et les articles (`items_sold`). Le coût est inconnu : tout le chiffre est « à coût inconnu »
     * (`unknown_revenue`), la marge ne se calcule pas. Aucun retour, aucune location, aucun abonnement.
     *
     * @return array<string, array<string, mixed>> jour Y-m-d => agrégats (jours sans vente absents)
     */
    public function dailySales(string $fromDay, string $toDay): array
    {
        try {
            $rows = $this->conn()->fetchAllAssociative(
                "SELECT day AS d, SUM(net_cents) AS amount, COUNT(*) AS orders, SUM(items_count) AS items FROM import_order WHERE state = 'paid' AND day BETWEEN ? AND ? GROUP BY day ORDER BY day",
                [$fromDay, $toDay]
            );
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $day = substr((string) $row['d'], 0, 10);
            $out[$day] = ['d' => $day, 'sales_amount' => (int) $row['amount'], 'returns_amount' => 0, 'items_sold' => (int) $row['items'], 'items_returned' => 0,
                'orders_sold' => (int) $row['orders'], 'orders_returned' => 0, 'unknown_revenue' => (int) $row['amount'], 'unknown_items' => (int) $row['items'], 'cogs' => 0, 'cogs_items' => 0,
                'rentals' => 0, 'subscriptions' => 0];
        }

        return $out;
    }

    /**
     * La série « ventes d'avant la migration », à part des ventes du site : totaux, mois par mois, et le compte des commandes par état.
     * Sans bornes : toute l'archive.
     *
     * @return array{from: ?string, to: ?string, firstDay: ?string, lastDay: ?string, totals: array{revenue: int, orders: int, items: int, averageBasket: ?int},
     *   months: list<array{month: string, revenue: int, orders: int, items: int}>, states: array<string, int>}
     */
    public function series(?string $fromDay, ?string $toDay): array
    {
        $where = '';
        $params = [];
        if ($fromDay !== null) {
            $where .= ' AND day >= ?';
            $params[] = $fromDay;
        }
        if ($toDay !== null) {
            $where .= ' AND day <= ?';
            $params[] = $toDay;
        }
        $conn = $this->conn();
        $months = [];
        $totals = ['revenue' => 0, 'orders' => 0, 'items' => 0];
        foreach ($conn->fetchAllAssociative("SELECT TO_CHAR(day, 'YYYY-MM') AS month, SUM(net_cents) AS revenue, COUNT(*) AS orders, SUM(items_count) AS items FROM import_order WHERE state = 'paid'$where GROUP BY 1 ORDER BY 1", $params) as $row) {
            $months[] = ['month' => (string) $row['month'], 'revenue' => (int) $row['revenue'], 'orders' => (int) $row['orders'], 'items' => (int) $row['items']];
            $totals['revenue'] += (int) $row['revenue'];
            $totals['orders'] += (int) $row['orders'];
            $totals['items'] += (int) $row['items'];
        }
        $states = array_fill_keys([OrderImportService::PAID, OrderImportService::REFUNDED, OrderImportService::CANCELLED, OrderImportService::UNPAID], 0);
        foreach ($conn->fetchAllAssociative('SELECT state, COUNT(*) AS n FROM import_order WHERE TRUE' . $where . ' GROUP BY state', $params) as $row) {
            $states[(string) $row['state']] = (int) $row['n'];
        }
        $bounds = $conn->fetchAssociative("SELECT MIN(day) AS first, MAX(day) AS last FROM import_order WHERE state = 'paid'$where", $params) ?: ['first' => null, 'last' => null];

        return ['from' => $fromDay, 'to' => $toDay, 'firstDay' => $bounds['first'] !== null ? substr((string) $bounds['first'], 0, 10) : null, 'lastDay' => $bounds['last'] !== null ? substr((string) $bounds['last'], 0, 10) : null,
            'totals' => $totals + ['averageBasket' => $totals['orders'] > 0 ? (int) round($totals['revenue'] / $totals['orders']) : null], 'months' => $months, 'states' => $states];
    }

    /**
     * Les commandes d'avant d'un compte du site, de la plus récente à la plus ancienne, avec leurs lignes. Aucune donnée personnelle :
     * l'archive n'en garde pas.
     *
     * @return array{orders: list<array<string, mixed>>, page: int, pages: int, total: int}
     */
    public function ordersOf(int $userId, int $page = 1): array
    {
        $conn = $this->conn();
        $page = max(1, $page);
        $total = (int) $conn->fetchOne('SELECT COUNT(*) FROM import_order WHERE user_id = ?', [$userId]);
        $rows = $conn->fetchAllAssociative('SELECT id, ref, ordered_at, day, state, financial_status, total_cents, tax_cents, shipping_cents, refunded_cents, net_cents, currency, items_count FROM import_order WHERE user_id = ?'
            . ' ORDER BY ordered_at DESC, id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), [$userId]);
        $lines = [];
        if ($rows !== []) {
            foreach ($conn->fetchAllAssociative('SELECT order_id, name, quantity, unit_price_cents, product_id FROM import_order_line WHERE order_id IN (' . implode(',', array_map(static fn (array $r): int => (int) $r['id'], $rows)) . ') ORDER BY order_id, position') as $line) {
                $lines[(int) $line['order_id']][] = ['name' => (string) $line['name'], 'quantity' => (int) $line['quantity'], 'unitPrice' => (int) $line['unit_price_cents'], 'productId' => $line['product_id'] !== null ? (int) $line['product_id'] : null];
            }
        }

        return ['orders' => array_map(static fn (array $r): array => [
            'ref' => (string) $r['ref'], 'orderedAt' => (new \DateTimeImmutable((string) $r['ordered_at'], new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM), 'day' => substr((string) $r['day'], 0, 10),
            'state' => (string) $r['state'], 'financialStatus' => (string) $r['financial_status'], 'total' => (int) $r['total_cents'], 'taxes' => (int) $r['tax_cents'], 'shipping' => (int) $r['shipping_cents'],
            'refunded' => (int) $r['refunded_cents'], 'net' => (int) $r['net_cents'], 'currency' => (string) $r['currency'], 'items' => (int) $r['items_count'], 'lines' => $lines[(int) $r['id']] ?? [],
        ], $rows), 'page' => $page, 'pages' => max(1, (int) ceil($total / self::PER_PAGE)), 'total' => $total];
    }

    private function conn(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
