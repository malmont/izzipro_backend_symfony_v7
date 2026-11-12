<?php

namespace App\Gestion\Services;

use App\Entity\Collections;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;

/**
 * Résultat d'une période de gestion (lot P2, 10/11/2026, contrat validé par l'application : `docs/periodes-gestion.md` § 3).
 * Deux requêtes agrégées sur les ventes (commandes clients datées dans la période au fuseau du magasin, types vente 1 et
 * retour 2, statuts « incomplète » et « annulée » exclus, cartes-cadeaux exclues) plus les totaux d'achat de la période
 * (`PurchaseMetricsCalculator::periodTotals`). Montants en cents entiers dans la devise du site, hors taxes (lignes TTC ramenées
 * au prorata de la taxe de la commande), nets des remises (les lignes sont enregistrées nettes) ; taux en %, 2 décimales, null
 * quand le dénominateur est nul.
 *
 * - `sales` : ventes d'articles seulement (lignes sans réservation, commandes sans abonnement) ; `revenue` = ventes − retours ;
 *   `unknownCostRevenue` = part de `revenue` dont le coût est inconnu ; `shippingBilled` = livraison facturée, hors `revenue`.
 * - `otherRevenue` : lignes de location (réservation liée) et commandes d'abonnement, hors résultat.
 * - `cogs` : Σ quantité × coût unitaire des lignes au coût connu ; retours déduits au même coût ; coût unitaire = coût de la
 *   commande fournisseur du produit × taux (G6) si le rattachement est antérieur à la coupure (`$costCutoff` : échéance ou
 *   clôture d'une période fermée), sinon `purchase_price`, sinon inconnu (`unknownCostItems`, jamais le prix de vente) ;
 *   `estimatedItems` / `estimatedAmount` = part au coût estimé par la reprise d'historique (lot P3), comprise dans `amount`.
 * - `result` : `gross` = (revenue − unknownCostRevenue) − cogs ; `net` = gross − expenses.total ; `tauxMarge` = gross / cogs ;
 *   `tauxMarque` = gross / (revenue − unknownCostRevenue) ; `netRate` = net / (revenue − unknownCostRevenue).
 * - `expenses` : frais de port des commandes fournisseurs et notes de frais datés dans la période ; `other` extensible (publicité…).
 * - `investment` : commandes fournisseurs de la période (coût × quantité × taux) + leurs frais de port ; informatif.
 */
class PeriodResultCalculator
{
    public const SOURCES = [1 => 'ecommerce', 2 => 'pos', 3 => 'mobile_app'];
    /** Part hors taxes d'un montant de ligne (prix TTC ramenés au hors taxes) ; partagée avec les statistiques des locations (LOC1) */
    public const NET_FACTOR = 'CASE WHEN o.prices_include_tax AND COALESCE(o.sub_total, 0) <> 0 THEN (o.sub_total - COALESCE(o.total_tax, 0)) / o.sub_total ELSE 1 END';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly StoreClock $clock,
        private readonly PurchaseMetricsCalculator $purchases,
        private readonly PeriodCalendar $calendar,
        private readonly ?TenantCurrencyProvider $currency = null,
        private readonly ?GestionFixedCostService $fixedCosts = null, // lot FF1
    ) {
    }

    /** @return array<string, mixed> charge utile du résultat (sans `frozen` / `frozenAt`, posés par le use case) */
    /**
     * @param bool $legacy calcul d'avant le lot L0 (coût du produit rattaché), pour la seule vérification `app:gestion:lignes:verifier`
     */
    public function compute(Collections $period, ?\DateTimeImmutable $costCutoff, bool $legacy = false): array
    {
        [$from, $to] = $this->clock->range($period->getStartDateCollection()->format('Y-m-d'), $period->getEndDateCollection()->format('Y-m-d'));
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];
        $row = $this->salesRow($params['from'], $params['to'], $costCutoff, $legacy);
        $shippingBilled = (int) round((float) $conn->fetchOne(
            'SELECT COALESCE(SUM(CASE WHEN o.order_type_id = 2 THEN -ABS(COALESCE(o.shipping_cost, 0)) ELSE COALESCE(o.shipping_cost, 0) END), 0) FROM "order" o'
            . ' WHERE o.order_date >= :from AND o.order_date < :to AND o.order_type_id IN (1, 2) AND o.status_id NOT IN (1, 7)',
            ['from' => $params['from'], 'to' => $params['to']]
        ));
        $totals = fn () => $this->purchases->periodTotals($period->getStartDateCollection(), $period->getEndDateCollection());
        $purchases = $legacy ? $this->purchases->legacy($totals) : $totals();

        $c = static fn (mixed $v): int => (int) round((float) $v);
        $revenue = $c($row['sales_amount'] ?? 0) - $c($row['returns_amount'] ?? 0);
        $unknownRevenue = $c($row['unknown_revenue'] ?? 0);
        $cogs = $c($row['cogs'] ?? 0);
        $rentals = $c($row['rentals'] ?? 0);
        $subscriptions = $c($row['subscriptions'] ?? 0);
        $shipping = (int) ($purchases['shippingCost'] ?? 0);
        $notes = (int) ($purchases['expenseCost'] ?? 0);
        $advertising = $this->advertising($period);
        $other = $advertising['campaigns'] === [] ? [] : [['kind' => 'advertising', 'label' => 'Publicité', 'amount' => $advertising['amount'], 'campaigns' => $advertising['campaigns']]];
        $expensesTotal = $shipping + $notes + $advertising['amount'];
        $effective = $revenue - $unknownRevenue; // ventes au coût connu : seule base des marges (décision « exclu des marges »)
        $gross = $effective - $cogs;
        $net = $gross - $expensesTotal;
        $rate = static fn (int $num, int $den): ?float => $den > 0 ? round($num / $den * 100, 2) : null;
        // lot FF1 : frais fixes au prorata des jours de la période, en ajout (net inchangé) ; figés avec le résultat à la fermeture
        $fixed = $this->fixedCosts?->between($period->getStartDateCollection()->format('Y-m-d'), $period->getEndDateCollection()->format('Y-m-d'))
            ?? ['total' => 0, 'byCategory' => [], 'days' => 0, 'caveats' => []];
        $netAfterFixed = $net - $fixed['total'];
        $investmentPurchases = (int) ($purchases['purchaseCost'] ?? 0);

        return [
            'period' => [
                'id' => $period->getId(), 'name' => $period->getNomCollection(), 'periodKey' => $period->getPeriodKey(),
                'start' => $period->getStartDateCollection()->format('Y-m-d'), 'end' => $period->getEndDateCollection()->format('Y-m-d'),
                'status' => $this->calendar->status($period), 'closedAt' => $this->calendar->closedAt($period)?->format(\DateTimeInterface::ATOM),
                'reopenedAt' => $period->getReopenedAt()?->format(\DateTimeInterface::ATOM),
            ],
            'currency' => $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT,
            'sales' => [
                'revenue' => $revenue, 'orders' => (int) ($row['orders_sold'] ?? 0), 'items' => (int) ($row['items_sold'] ?? 0),
                'returns' => ['amount' => $c($row['returns_amount'] ?? 0), 'orders' => (int) ($row['orders_returned'] ?? 0), 'items' => (int) ($row['items_returned'] ?? 0)],
                'unknownCostRevenue' => $unknownRevenue,
                'shippingBilled' => $shippingBilled,
                'bySource' => ['ecommerce' => $c($row['src_1'] ?? 0), 'pos' => $c($row['src_2'] ?? 0), 'mobile_app' => $c($row['src_3'] ?? 0)],
            ],
            'otherRevenue' => ['rentals' => $rentals, 'subscriptions' => $subscriptions, 'total' => $rentals + $subscriptions],
            'cogs' => ['amount' => $cogs, 'items' => (int) ($row['cogs_items'] ?? 0), 'unknownCostItems' => (int) ($row['unknown_items'] ?? 0),
                'estimatedItems' => (int) ($row['estimated_items'] ?? 0), 'estimatedAmount' => $c($row['estimated_amount'] ?? 0)], // part estimée (reprise d'historique, lot P3), déjà comprise dans amount
            'expenses' => ['shipping' => $shipping, 'notes' => $notes, 'other' => $other, 'total' => $expensesTotal], // other : publicité (lot A1), extensible
            'result' => ['gross' => $gross, 'net' => $net, 'tauxMarge' => $rate($gross, $cogs), 'tauxMarque' => $rate($gross, $effective), 'netRate' => $rate($net, $effective),
                'netAfterFixed' => $netAfterFixed, 'netAfterFixedRate' => $rate($netAfterFixed, $effective)],
            'fixedCosts' => $fixed,
            'investment' => ['purchases' => $investmentPurchases, 'shipping' => $shipping, 'total' => $investmentPurchases + $shipping],
        ];
    }

    /**
     * Agrégats des lignes vendues entre deux instants UTC (mêmes règles que le résultat : ventes d'articles nettes des retours, hors taxes,
     * coût moyen pondéré des lignes d'achat à la date de la vente, coupure facultative), éventuellement limitées à des articles.
     *
     * @param list<int>|null $productIds null = tous les articles
     * @return array<string, mixed>
     */
    private function salesRow(string $fromUtc, string $toUtc, ?\DateTimeImmutable $costCutoff, bool $legacy, ?array $productIds = null): array
    {
        return $this->salesRows($fromUtc, $toUtc, $costCutoff, $legacy, $productIds, null, false)[0] ?? [];
    }

    /**
     * Agrégats des lignes vendues **par jour du magasin** sur [fromDay, toDay] (inclus, Y-m-d), mêmes règles que le résultat (statistiques
     * unifiées S1, 11/11/2026, `docs/statistiques-gestion.md`), éventuellement pour une source (1 boutique web, 2 caisse, 3 application).
     *
     * @return array<string, array<string, mixed>> jour Y-m-d => agrégats (jours sans vente absents)
     */
    public function dailySales(string $fromDay, string $toDay, ?int $source = null): array
    {
        [$from, $to] = $this->clock->range($fromDay, $toDay);
        $out = [];
        foreach ($this->salesRows($from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), null, false, null, $source, true) as $row) {
            $out[substr((string) $row['d'], 0, 10)] = $row;
        }

        return $out;
    }

    /**
     * Agrégats des ventes entre deux instants (lot JOUR, 13/11/2026) : même requête que `dailySales`, bornée à la seconde (comparaison
     * « à la même heure »). Une ligne de totaux, à passer à GestionStatisticsService::metrics.
     *
     * @return list<array<string, mixed>>
     */
    public function salesBetween(\DateTimeInterface $fromUtc, \DateTimeInterface $toUtc, ?int $source = null): array
    {
        return $this->salesRows($fromUtc->format('Y-m-d H:i:s'), $toUtc->format('Y-m-d H:i:s'), null, false, null, $source, false);
    }

    /** @return list<array<string, mixed>> une ligne (totaux) ou une ligne par jour du magasin (`$byDay`, colonne `d`) */
    private function salesRows(string $fromUtc, string $toUtc, ?\DateTimeImmutable $costCutoff, bool $legacy, ?array $productIds, ?int $source, bool $byDay): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $params = ['from' => $fromUtc, 'to' => $toUtc];
        $attachedBeforeCutoff = '';
        if ($costCutoff !== null) {
            $attachedBeforeCutoff = $legacy ? ' AND (p.commande_attached_at IS NULL OR p.commande_attached_at <= :cutoff)' : ' AND l.attached_at <= :cutoff';
            $params['cutoff'] = $costCutoff->format('Y-m-d H:i:s');
        }
        $scope = '';
        $types = [];
        if ($productIds !== null) { // périmètre d'articles (rentabilité d'une campagne ciblée, lot A1)
            $scope = $productIds === [] ? ' AND FALSE' : ' AND p.id IN (:pids)';
            if ($productIds !== []) {
                $params['pids'] = array_values(array_map('intval', $productIds));
                $types['pids'] = \Doctrine\DBAL\ArrayParameterType::INTEGER;
            }
        }
        $factor = self::NET_FACTOR;
        if ($legacy) {
            $cost = 'CASE WHEN p.id IS NULL THEN NULL'
                . ' WHEN p.commande_cost_cents IS NOT NULL AND c.id IS NOT NULL' . $attachedBeforeCutoff . ' THEN p.commande_cost_cents * COALESCE(c.exchange_rate, 1)'
                . ' WHEN p.purchase_price IS NOT NULL THEN p.purchase_price ELSE NULL END';
            $estimated = "(CASE WHEN p.commande_cost_cents IS NOT NULL AND c.id IS NOT NULL$attachedBeforeCutoff THEN p.commande_cost_estimated ELSE false END)";
            $costJoin = ' LEFT JOIN commande c ON c.id = p.commande_id';
        } else {
            // lot L0 (10/11/2026) : coût moyen pondéré glissant des lignes d'achat de l'article dont la commande est datée au plus tard le jour de
            // la vente (jour du magasin) et, pour une période fermée, saisies avant la coupure ; lignes au coût inconnu exclues de la moyenne
            $params['tz'] = $this->clock->timezone()->getName();
            $costJoin = ' LEFT JOIN LATERAL (SELECT COALESCE(SUM(l.quantity_ordered * l.unit_cost_cents * COALESCE(lc.exchange_rate, 1)) / NULLIF(SUM(l.quantity_ordered), 0), AVG(l.unit_cost_cents * COALESCE(lc.exchange_rate, 1))) AS avg_cost, BOOL_OR(l.estimated) AS est'
                . ' FROM commande_line l JOIN commande lc ON lc.id = l.commande_id WHERE l.product_id = p.id AND l.unit_cost_cents IS NOT NULL'
                . " AND CAST(lc.date AS date) <= CAST((o.order_date AT TIME ZONE 'UTC') AT TIME ZONE :tz AS date)" . $attachedBeforeCutoff . ') lw ON TRUE';
            $cost = 'CASE WHEN p.id IS NULL THEN NULL WHEN lw.avg_cost IS NOT NULL THEN lw.avg_cost WHEN p.purchase_price IS NOT NULL THEN p.purchase_price ELSE NULL END';
            $estimated = '(lw.avg_cost IS NOT NULL AND COALESCE(lw.est, false))';
        }
        $lines = 'SELECT o.id AS oid, o.order_type_id AS t, o.order_source_id AS src, oi.quantity AS qty,'
            . " ABS(oi.total_price) * ($factor) AS net,"
            . " (CASE WHEN o.order_type_id = 2 THEN -ABS(oi.total_price) ELSE oi.total_price END) * ($factor) AS signed,"
            . ' (b.id IS NOT NULL) AS rental, (o.subscription_id IS NOT NULL) AS sub, (b.id IS NULL AND o.subscription_id IS NULL) AS article,'
            . " ($cost) AS cost,"
            . " $estimated AS estimated"
            . ' FROM order_items oi JOIN "order" o ON o.id = oi.order_associated_id'
            . ' LEFT JOIN booking b ON b.order_item_id = oi.id'
            . ' LEFT JOIN product_variant pv ON pv.id = oi.product_variant_id'
            . ' LEFT JOIN product p ON p.id = pv.product_id'
            . $costJoin
            . ' WHERE o.order_date >= :from AND o.order_date < :to AND o.order_type_id IN (1, 2) AND o.status_id NOT IN (1, 7)'
            . $scope;
        if ($source !== null) {
            $lines .= ' AND o.order_source_id = :src';
            $params['src'] = $source;
        }
        if ($byDay) {
            $params['tz'] = $this->clock->timezone()->getName();
            $lines = str_replace('SELECT o.id AS oid,', "SELECT CAST((o.order_date AT TIME ZONE 'UTC') AT TIME ZONE :tz AS date) AS d, o.id AS oid,", $lines);
        }

        return $conn->fetchAllAssociative(
            'SELECT ' . ($byDay ? 'd, ' : '') . 'COALESCE(SUM(net) FILTER (WHERE article AND t = 1), 0) AS sales_amount,'
            . ' COALESCE(SUM(net) FILTER (WHERE article AND t = 2), 0) AS returns_amount,'
            . ' COALESCE(SUM(qty) FILTER (WHERE article AND t = 1), 0) AS items_sold,'
            . ' COALESCE(SUM(qty) FILTER (WHERE article AND t = 2), 0) AS items_returned,'
            . ' COUNT(DISTINCT oid) FILTER (WHERE article AND t = 1) AS orders_sold,'
            . ' COUNT(DISTINCT oid) FILTER (WHERE article AND t = 2) AS orders_returned,'
            . ' COALESCE(SUM(signed) FILTER (WHERE article AND cost IS NULL), 0) AS unknown_revenue,'
            . ' COALESCE(SUM(qty) FILTER (WHERE article AND cost IS NULL AND t = 1), 0) AS unknown_items,'
            . ' COALESCE(SUM((CASE WHEN t = 2 THEN -qty ELSE qty END) * cost) FILTER (WHERE article AND cost IS NOT NULL), 0) AS cogs,'
            . ' COALESCE(SUM(qty) FILTER (WHERE article AND cost IS NOT NULL AND t = 1), 0) AS cogs_items,'
            . ' COALESCE(SUM(qty) FILTER (WHERE article AND estimated AND t = 1), 0) AS estimated_items,'
            . ' COALESCE(SUM((CASE WHEN t = 2 THEN -qty ELSE qty END) * cost) FILTER (WHERE article AND estimated), 0) AS estimated_amount,'
            . ' COALESCE(SUM(signed) FILTER (WHERE rental), 0) AS rentals,'
            . ' COALESCE(SUM(signed) FILTER (WHERE sub AND NOT rental), 0) AS subscriptions,'
            . ' COALESCE(SUM(signed) FILTER (WHERE article AND src = 1), 0) AS src_1,'
            . ' COALESCE(SUM(signed) FILTER (WHERE article AND src = 2), 0) AS src_2,'
            . ' COALESCE(SUM(signed) FILTER (WHERE article AND src = 3), 0) AS src_3'
            . " FROM ($lines) l" . ($byDay ? ' GROUP BY d ORDER BY d' : ''),
            $params,
            $types
        );
    }

    /**
     * Ventes d'articles et marge brute sur des jours du magasin [fromDay, toDay] (rentabilité perçue d'une campagne, lot A1) ; marge brute = ventes
     * au coût connu − coût des articles vendus ; la part au coût inconnu est exclue et rendue à part.
     *
     * @param list<int>|null $productIds null = tous les articles
     * @return array{revenue: int, grossMargin: int, orders: int, items: int, unknownCostRevenue: int}
     */
    public function salesMetrics(\DateTimeImmutable $fromDay, \DateTimeImmutable $toDay, ?array $productIds = null): array
    {
        [$from, $to] = $this->clock->range($fromDay->format('Y-m-d'), $toDay->format('Y-m-d'));
        $row = $this->salesRow($from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), null, false, $productIds);
        $c = static fn (mixed $v): int => (int) round((float) $v);
        $revenue = $c($row['sales_amount'] ?? 0) - $c($row['returns_amount'] ?? 0);
        $unknown = $c($row['unknown_revenue'] ?? 0);

        return ['revenue' => $revenue, 'grossMargin' => $revenue - $unknown - $c($row['cogs'] ?? 0), 'orders' => (int) ($row['orders_sold'] ?? 0), 'items' => (int) ($row['items_sold'] ?? 0), 'unknownCostRevenue' => $unknown];
    }

    /**
     * Publicité de la période (lot A1) : campagnes classées par leur date de début, montant ramené dans la devise du site.
     *
     * @return array{amount: int, campaigns: list<array{id: int, name: string, amount: int}>}
     */
    public function advertising(Collections $period): array
    {
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, name, ROUND(amount_cents * COALESCE(exchange_rate, 1)) AS amount FROM marketing_campaign WHERE start_date >= :from AND start_date <= :to ORDER BY start_date, id',
            ['from' => $period->getStartDateCollection()->format('Y-m-d'), 'to' => $period->getEndDateCollection()->format('Y-m-d')]
        );
        $campaigns = array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'amount' => (int) $r['amount']], $rows);

        return ['amount' => array_sum(array_column($campaigns, 'amount')), 'campaigns' => $campaigns];
    }
}
