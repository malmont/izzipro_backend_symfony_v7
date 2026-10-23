<?php

namespace App\Services\PosStatisticsService;

use App\Entity\CashRegister;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\StoreClock;
use App\Services\PosAuditService\PosApprovalService;
use App\Services\PosAuditService\PosAuditService;
use App\Services\TenantEntityManagerProvider;

/**
 * Statistiques par caissier (F14, 29/10/2026) : par vendeur RÉEL (order.user_id d'une vente de caisse = compte qui a encaissé ;
 * hors ligne : sellerId), journées dans le fuseau du magasin, période [from, to] par date de vente. Un caissier ne voit que ses
 * propres chiffres (cashierId forcé) ; responsable et administrateur voient tout. Données d'employés : finalité de gestion,
 * chaque consultation journalisée (CASHIER_STATS_VIEWED).
 */
class PosCashierStatisticsService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly StoreClock $clock, private readonly PosAuditService $audit)
    {
    }

    public function compute(User $actor, mixed $from, mixed $to, mixed $cashRegisterId, mixed $cashierId): array
    {
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        [$start, $end, $fromDay, $toDay] = $this->clock->range($from, $to);
        $manager = PosApprovalService::isManager($actor);
        $onlySelf = !$manager;
        $cashier = $onlySelf ? (int) $actor->getId() : (is_numeric($cashierId) ? (int) $cashierId : null);
        $register = is_numeric($cashRegisterId) ? $em->find(CashRegister::class, (int) $cashRegisterId) : null;
        if (is_numeric($cashRegisterId) && $register === null) {
            throw new CashRegisterException('CASH_REGISTER_NOT_FOUND', 'Poste de caisse introuvable', 404, [], ['cashRegisterId' => (int) $cashRegisterId]);
        }
        $p = ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')];
        $regSql = $register ? ' AND c.cash_register_id = :reg' : '';
        $userSql = $cashier !== null ? ' AND o.user_id_id = :cashier' : '';
        if ($register) {
            $p['reg'] = $register->getId();
        }
        if ($cashier !== null) {
            $p['cashier'] = $cashier;
        }
        $rows = [];
        $row = function (int $userId) use (&$rows) {
            return $rows[$userId] ??= ['cashierId' => $userId, 'sales' => ['count' => 0, 'grossHt' => 0, 'netHt' => 0, 'ttc' => 0, 'items' => 0, 'averageTicket' => 0, 'itemsPerTicket' => 0.0],
                'discounts' => ['count' => 0, 'amount' => 0], 'priceOverrides' => ['count' => 0, 'difference' => 0], 'customItems' => ['count' => 0, 'amount' => 0],
                'returns' => ['count' => 0, 'amount' => 0], 'cancellations' => ['count' => 0, 'amount' => 0], 'drawerOpenings' => 0,
                'approvals' => ['requested' => 0, 'granted' => 0], 'discrepancies' => ['sessions' => 0, 'total' => 0, 'absolute' => 0],
                'offlineSales' => 0, 'giftCardsSold' => ['count' => 0, 'amount' => 0], 'salesWithCustomer' => 0, 'loyaltyPointsEarned' => 0];
        };
        // Ventes (type 1) par vendeur : commandes de caisse de la période, rattachées à une session (poste) par leur transaction
        $sales = $conn->fetchAllAssociative('SELECT o.user_id_id AS uid, COUNT(DISTINCT o.id) AS n, COALESCE(SUM(o.total_amount), 0) AS ttc,
                COALESCE(SUM(CASE WHEN o.synced_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS offline, COALESCE(SUM(CASE WHEN o.customer_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS with_customer,
                COALESCE(SUM(CASE WHEN o.status_id = 7 THEN 1 ELSE 0 END), 0) AS canceled
            FROM "order" o JOIN transaction_caisse t ON t.order_caisse_id = o.id AND t.transaction_type_id = 1 JOIN caisse c ON c.id = t.caisse_id
            WHERE o.order_source_id = 2 AND o.order_type_id = 1 AND o.order_date >= :start AND o.order_date < :end' . $regSql . $userSql . ' GROUP BY o.user_id_id', $p);
        foreach ($sales as $s) {
            $r = &$rows[(int) $s['uid']];
            $r = $row((int) $s['uid']);
            $r['sales']['count'] = (int) $s['n'];
            $r['sales']['ttc'] = (int) round((float) $s['ttc']);
            $r['offlineSales'] = (int) $s['offline'];
            $r['salesWithCustomer'] = (int) $s['with_customer'];
            unset($r);
        }
        $lines = $conn->fetchAllAssociative('SELECT o.user_id_id AS uid, COALESCE(SUM(i.total_price), 0) AS net, COALESCE(SUM(i.total_price + i.discount_amount), 0) AS gross, COALESCE(SUM(i.quantity), 0) AS qty,
                COALESCE(SUM(i.discount_amount), 0) AS discounts, COUNT(DISTINCT CASE WHEN i.discount_amount > 0 THEN o.id END) AS discounted,
                COUNT(CASE WHEN i.catalog_unit_price IS NOT NULL THEN 1 END) AS overrides, COALESCE(SUM(CASE WHEN i.catalog_unit_price IS NOT NULL THEN (i.catalog_unit_price - i.unit_price) * i.quantity ELSE 0 END), 0) AS override_diff,
                COUNT(CASE WHEN i.custom_label IS NOT NULL AND i.product_variant_id IS NULL THEN 1 END) AS custom_n, COALESCE(SUM(CASE WHEN i.custom_label IS NOT NULL AND i.product_variant_id IS NULL THEN i.total_price ELSE 0 END), 0) AS custom_amount
            FROM "order" o JOIN order_items i ON i.order_associated_id = o.id JOIN transaction_caisse t ON t.order_caisse_id = o.id AND t.transaction_type_id = 1 JOIN caisse c ON c.id = t.caisse_id
            WHERE o.order_source_id = 2 AND o.order_type_id = 1 AND o.order_date >= :start AND o.order_date < :end' . $regSql . $userSql . ' GROUP BY o.user_id_id', $p);
        foreach ($lines as $l) {
            $r = &$rows[(int) $l['uid']];
            $r = $row((int) $l['uid']);
            $r['sales']['netHt'] = (int) round((float) $l['net']);
            $r['sales']['grossHt'] = (int) round((float) $l['gross']);
            $r['sales']['items'] = (int) $l['qty'];
            $r['discounts'] = ['count' => (int) $l['discounted'], 'amount' => (int) round((float) $l['discounts'])];
            $r['priceOverrides'] = ['count' => (int) $l['overrides'], 'difference' => (int) round((float) $l['override_diff'])];
            $r['customItems'] = ['count' => (int) $l['custom_n'], 'amount' => (int) round((float) $l['custom_amount'])];
            unset($r);
        }
        // Cartes-cadeaux vendues (type 3)
        foreach ($conn->fetchAllAssociative('SELECT o.user_id_id AS uid, COUNT(*) AS n, COALESCE(SUM(o.total_amount), 0) AS total FROM "order" o JOIN transaction_caisse t ON t.order_caisse_id = o.id AND t.transaction_type_id = 9 JOIN caisse c ON c.id = t.caisse_id
            WHERE o.order_source_id = 2 AND o.order_type_id = 3 AND o.order_date >= :start AND o.order_date < :end' . $regSql . $userSql . ' GROUP BY o.user_id_id', $p) as $g) {
            $r = &$rows[(int) $g['uid']];
            $r = $row((int) $g['uid']);
            $r['giftCardsSold'] = ['count' => (int) $g['n'], 'amount' => (int) round((float) $g['total'])];
            unset($r);
        }
        // Retours F2 (par celui qui les a faits) et annulations (journal REFUND kind cancellation, par celui qui a annulé)
        $retUser = $cashier !== null ? ' AND r.user_id = :cashier' : '';
        foreach ($conn->fetchAllAssociative('SELECT r.user_id AS uid, COUNT(*) AS n, COALESCE(SUM(r.amount), 0) AS total FROM pos_return r JOIN caisse c ON c.id = r.caisse_id WHERE r.created_at >= :start AND r.created_at < :end' . $regSql . $retUser . ' GROUP BY r.user_id', $p) as $x) {
            $r = &$rows[(int) $x['uid']];
            $r = $row((int) $x['uid']);
            $r['returns'] = ['count' => (int) $x['n'], 'amount' => (int) round((float) $x['total'])];
            unset($r);
        }
        $logUser = $cashier !== null ? ' AND a.user_id = :cashier' : '';
        $logReg = $register ? ' AND a.cash_register_id = :reg' : '';
        foreach ($conn->fetchAllAssociative('SELECT a.user_id AS uid, a.payload FROM pos_audit_log a WHERE a.type = \'REFUND\' AND a.payload LIKE \'%"kind":"cancellation"%\' AND a.occurred_at >= :start AND a.occurred_at < :end AND a.user_id IS NOT NULL' . $logReg . $logUser, $p) as $x) {
            $r = &$rows[(int) $x['uid']];
            $r = $row((int) $x['uid']);
            $data = json_decode($x['payload'], true);
            $r['cancellations']['count']++;
            $r['cancellations']['amount'] += (int) (($data['data'] ?? $data)['refundAmount'] ?? 0);
            unset($r);
        }
        // Ouvertures du tiroir sans vente, validations demandées (demandeur) et données (responsable)
        foreach ($conn->fetchAllAssociative('SELECT a.user_id AS uid, COUNT(*) AS n FROM pos_audit_log a WHERE a.type = \'DRAWER_OPENED\' AND a.occurred_at >= :start AND a.occurred_at < :end AND a.user_id IS NOT NULL' . $logReg . $logUser . ' GROUP BY a.user_id', $p) as $x) {
            $r = &$rows[(int) $x['uid']];
            $r = $row((int) $x['uid']);
            $r['drawerOpenings'] = (int) $x['n'];
            unset($r);
        }
        $apReq = $cashier !== null ? ' AND requested_by_id = :cashier' : '';
        foreach ($conn->fetchAllAssociative('SELECT requested_by_id AS uid, COUNT(*) AS n FROM pos_approval WHERE created_at >= :start AND created_at < :end' . $apReq . ' GROUP BY requested_by_id', $p) as $x) {
            $r = &$rows[(int) $x['uid']];
            $r = $row((int) $x['uid']);
            $r['approvals']['requested'] = (int) $x['n'];
            unset($r);
        }
        $apMan = $cashier !== null ? ' AND manager_id = :cashier' : '';
        foreach ($conn->fetchAllAssociative('SELECT manager_id AS uid, COUNT(*) AS n FROM pos_approval WHERE created_at >= :start AND created_at < :end' . $apMan . ' GROUP BY manager_id', $p) as $x) {
            $r = &$rows[(int) $x['uid']];
            $r = $row((int) $x['uid']);
            $r['approvals']['granted'] = (int) $x['n'];
            unset($r);
        }
        // Écarts des sessions fermées par le caissier (rapports Z clôturés dans la période)
        $zUser = $cashier !== null ? ' AND z.closed_by_id = :cashier' : '';
        $zReg = $register ? ' AND z.cash_register_id = :reg' : '';
        foreach ($conn->fetchAllAssociative('SELECT z.closed_by_id AS uid, COUNT(*) AS n, COALESCE(SUM(z.discrepancy), 0) AS total, COALESCE(SUM(ABS(z.discrepancy)), 0) AS absolute FROM cash_register_report z WHERE z.closed_at >= :start AND z.closed_at < :end AND z.closed_by_id IS NOT NULL' . $zReg . $zUser . ' GROUP BY z.closed_by_id', $p) as $x) {
            $r = &$rows[(int) $x['uid']];
            $r = $row((int) $x['uid']);
            $r['discrepancies'] = ['sessions' => (int) $x['n'], 'total' => (int) $x['total'], 'absolute' => (int) $x['absolute']];
            unset($r);
        }
        // Points de fidélité attribués par les ventes du vendeur
        foreach ($conn->fetchAllAssociative('SELECT o.user_id_id AS uid, COALESCE(SUM(l.points), 0) AS pts FROM pos_loyalty_transaction l JOIN "order" o ON o.id = l.order_id JOIN transaction_caisse t ON t.order_caisse_id = o.id AND t.transaction_type_id = 1 JOIN caisse c ON c.id = t.caisse_id
            WHERE l.kind = \'earn\' AND o.order_date >= :start AND o.order_date < :end' . $regSql . $userSql . ' GROUP BY o.user_id_id', $p) as $x) {
            $r = &$rows[(int) $x['uid']];
            $r = $row((int) $x['uid']);
            $r['loyaltyPointsEarned'] = (int) $x['pts'];
            unset($r);
        }
        // Noms et moyennes
        $ids = array_keys($rows);
        $names = $ids ? $conn->fetchAllAssociativeIndexed('SELECT id, firstname, lastname, email FROM "user" WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')') : [];
        $total = $row(0);
        $total['cashierId'] = null;
        $total['cashier'] = ['name' => 'Total'];
        unset($rows[0]);
        foreach ($rows as $uid => &$r) {
            $u = $names[$uid] ?? null;
            $r['cashier'] = ['id' => $uid, 'name' => $u ? trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? '')) : ('#' . $uid), 'email' => $manager ? ($u['email'] ?? null) : null];
            $r['sales']['averageTicket'] = $r['sales']['count'] > 0 ? (int) round($r['sales']['ttc'] / $r['sales']['count']) : 0;
            $r['sales']['itemsPerTicket'] = $r['sales']['count'] > 0 ? round($r['sales']['items'] / $r['sales']['count'], 2) : 0.0;
            foreach (['count', 'grossHt', 'netHt', 'ttc', 'items'] as $k) {
                $total['sales'][$k] += $r['sales'][$k];
            }
            foreach (['discounts', 'returns', 'cancellations', 'giftCardsSold'] as $k) {
                $total[$k]['count'] += $r[$k]['count'];
                $total[$k]['amount'] += $r[$k]['amount'];
            }
            $total['priceOverrides']['count'] += $r['priceOverrides']['count'];
            $total['priceOverrides']['difference'] += $r['priceOverrides']['difference'];
            $total['customItems']['count'] += $r['customItems']['count'];
            $total['customItems']['amount'] += $r['customItems']['amount'];
            $total['drawerOpenings'] += $r['drawerOpenings'];
            $total['approvals']['requested'] += $r['approvals']['requested'];
            $total['approvals']['granted'] += $r['approvals']['granted'];
            foreach (['sessions', 'total', 'absolute'] as $k) {
                $total['discrepancies'][$k] += $r['discrepancies'][$k];
            }
            $total['offlineSales'] += $r['offlineSales'];
            $total['salesWithCustomer'] += $r['salesWithCustomer'];
            $total['loyaltyPointsEarned'] += $r['loyaltyPointsEarned'];
        }
        unset($r);
        $total['sales']['averageTicket'] = $total['sales']['count'] > 0 ? (int) round($total['sales']['ttc'] / $total['sales']['count']) : 0;
        $total['sales']['itemsPerTicket'] = $total['sales']['count'] > 0 ? round($total['sales']['items'] / $total['sales']['count'], 2) : 0.0;
        usort($rows, fn ($a, $b) => $b['sales']['ttc'] <=> $a['sales']['ttc']);
        $this->audit->record(PosAuditService::CASHIER_STATS_VIEWED, ['from' => $fromDay, 'to' => $toDay, 'cashRegisterId' => $register?->getId(), 'cashierId' => $cashier, 'scope' => $onlySelf ? 'self' : ($cashier !== null ? 'one' : 'all'), 'rows' => count($rows)], null, $register, 'statistics', null, $actor);

        return ['period' => ['from' => $fromDay, 'to' => $toDay, 'timezone' => $this->clock->settings()['timezone'], 'cashRegisterId' => $register?->getId(), 'cashierId' => $cashier, 'scope' => $onlySelf ? 'self' : 'all'],
            'cashiers' => array_values($rows), 'total' => $total];
    }
}
