<?php

namespace App\Services\GestionService;

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

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly StoreClock $clock,
        private readonly PurchaseMetricsCalculator $purchases,
        private readonly PeriodCalendar $calendar,
        private readonly ?TenantCurrencyProvider $currency = null,
    ) {
    }

    /** @return array<string, mixed> charge utile du résultat (sans `frozen` / `frozenAt`, posés par le use case) */
    public function compute(Collections $period, ?\DateTimeImmutable $costCutoff): array
    {
        [$from, $to] = $this->clock->range($period->getStartDateCollection()->format('Y-m-d'), $period->getEndDateCollection()->format('Y-m-d'));
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];
        $attachedBeforeCutoff = '';
        if ($costCutoff !== null) {
            $attachedBeforeCutoff = ' AND (p.commande_attached_at IS NULL OR p.commande_attached_at <= :cutoff)';
            $params['cutoff'] = $costCutoff->format('Y-m-d H:i:s');
        }
        $factor = 'CASE WHEN o.prices_include_tax AND COALESCE(o.sub_total, 0) <> 0 THEN (o.sub_total - COALESCE(o.total_tax, 0)) / o.sub_total ELSE 1 END';
        $cost = 'CASE WHEN p.id IS NULL THEN NULL'
            . ' WHEN p.commande_cost_cents IS NOT NULL AND c.id IS NOT NULL' . $attachedBeforeCutoff . ' THEN p.commande_cost_cents * COALESCE(c.exchange_rate, 1)'
            . ' WHEN p.purchase_price IS NOT NULL THEN p.purchase_price ELSE NULL END';
        $lines = 'SELECT o.id AS oid, o.order_type_id AS t, o.order_source_id AS src, oi.quantity AS qty,'
            . " ABS(oi.total_price) * ($factor) AS net,"
            . " (CASE WHEN o.order_type_id = 2 THEN -ABS(oi.total_price) ELSE oi.total_price END) * ($factor) AS signed,"
            . ' (b.id IS NOT NULL) AS rental, (o.subscription_id IS NOT NULL) AS sub, (b.id IS NULL AND o.subscription_id IS NULL) AS article,'
            . " ($cost) AS cost,"
            . " (CASE WHEN p.commande_cost_cents IS NOT NULL AND c.id IS NOT NULL" . $attachedBeforeCutoff . " THEN p.commande_cost_estimated ELSE false END) AS estimated"
            . ' FROM order_items oi JOIN "order" o ON o.id = oi.order_associated_id'
            . ' LEFT JOIN booking b ON b.order_item_id = oi.id'
            . ' LEFT JOIN product_variant pv ON pv.id = oi.product_variant_id'
            . ' LEFT JOIN product p ON p.id = pv.product_id'
            . ' LEFT JOIN commande c ON c.id = p.commande_id'
            . ' WHERE o.order_date >= :from AND o.order_date < :to AND o.order_type_id IN (1, 2) AND o.status_id NOT IN (1, 7)';
        $row = $conn->fetchAssociative(
            'SELECT COALESCE(SUM(net) FILTER (WHERE article AND t = 1), 0) AS sales_amount,'
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
            . " FROM ($lines) l",
            $params
        ) ?: [];
        $shippingBilled = (int) round((float) $conn->fetchOne(
            'SELECT COALESCE(SUM(CASE WHEN o.order_type_id = 2 THEN -ABS(COALESCE(o.shipping_cost, 0)) ELSE COALESCE(o.shipping_cost, 0) END), 0) FROM "order" o'
            . ' WHERE o.order_date >= :from AND o.order_date < :to AND o.order_type_id IN (1, 2) AND o.status_id NOT IN (1, 7)',
            ['from' => $params['from'], 'to' => $params['to']]
        ));
        $purchases = $this->purchases->periodTotals($period->getStartDateCollection(), $period->getEndDateCollection());

        $c = static fn (mixed $v): int => (int) round((float) $v);
        $revenue = $c($row['sales_amount'] ?? 0) - $c($row['returns_amount'] ?? 0);
        $unknownRevenue = $c($row['unknown_revenue'] ?? 0);
        $cogs = $c($row['cogs'] ?? 0);
        $rentals = $c($row['rentals'] ?? 0);
        $subscriptions = $c($row['subscriptions'] ?? 0);
        $shipping = (int) ($purchases['shippingCost'] ?? 0);
        $notes = (int) ($purchases['expenseCost'] ?? 0);
        $expensesTotal = $shipping + $notes;
        $effective = $revenue - $unknownRevenue; // ventes au coût connu : seule base des marges (décision « exclu des marges »)
        $gross = $effective - $cogs;
        $net = $gross - $expensesTotal;
        $rate = static fn (int $num, int $den): ?float => $den > 0 ? round($num / $den * 100, 2) : null;
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
            'expenses' => ['shipping' => $shipping, 'notes' => $notes, 'other' => [], 'total' => $expensesTotal],
            'result' => ['gross' => $gross, 'net' => $net, 'tauxMarge' => $rate($gross, $cogs), 'tauxMarque' => $rate($gross, $effective), 'netRate' => $rate($net, $effective)],
            'investment' => ['purchases' => $investmentPurchases, 'shipping' => $shipping, 'total' => $investmentPurchases + $shipping],
        ];
    }
}
