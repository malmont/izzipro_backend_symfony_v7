<?php

namespace App\LossPrevention\Services\Rule;

use App\LossPrevention\Entity\LossEvidence;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Services\LossAnalysisContext;
use App\LossPrevention\Services\LossAuditEntry;
use App\LossPrevention\Services\LossRuleCatalog;

/**
 * Variante « par personne » de DISCOUNT_PATTERN (vague 2) : N ventes en D jours, encaissées par le même compte, dont la remise atteint au moins
 * S % du plafond du caissier (en pourcentage du brut OU en montant : les deux plafonds de la caisse). Interrupteur à part, par pays et par site.
 * Les ventes sont relues dans les commandes de caisse (remises des lignes, prix bruts), pas dans le journal. Un constat par fenêtre et par
 * compte, complété tant qu'elle court.
 */
class DiscountSeriesRule implements LossRuleInterface
{
    public function code(): string
    {
        return LossRuleCatalog::DISCOUNT_PATTERN;
    }

    public function types(): array
    {
        return ['DISCOUNT_APPLIED'];
    }

    public function handle(array $entry, LossAnalysisContext $ctx): void
    {
        $cfg = $ctx->rules[$this->code()];
        if (!$cfg['personEnabled'] || $entry['userId'] === null || ($entry['subject']['type'] ?? null) !== 'order' || $entry['subject']['id'] === null) {
            return;
        }
        $params = $cfg['personParams'];
        $orderDate = $ctx->conn->fetchOne('SELECT order_date FROM "order" WHERE id = ?', [$entry['subject']['id']]);
        if (!is_string($orderDate) || $orderDate === '') {
            return;
        }
        $at = LossAuditEntry::utc($orderDate);
        $since = $at->modify('-' . $params['days'] . ' days');
        $caps = $ctx->conn->fetchAssociative('SELECT pos_discount_cashier_max_percent_bp AS bp, pos_discount_cashier_max_amount AS amount FROM entreprise ORDER BY id LIMIT 1') ?: [];
        $capBp = (int) ($caps['bp'] ?? 1000);
        $capAmount = (int) ($caps['amount'] ?? 2000);
        $near = [];
        foreach ($ctx->conn->fetchAllAssociative(
            'SELECT o.id, o.reference, o.order_date, COALESCE(SUM(i.discount_amount), 0) AS discount, COALESCE(SUM(i.unit_price * i.quantity), 0) AS gross FROM "order" o JOIN order_items i ON i.order_associated_id = o.id'
            . ' WHERE o.user_id_id = ? AND o.order_source_id = 2 AND o.order_date > ? AND o.order_date <= ? GROUP BY o.id, o.reference, o.order_date HAVING COALESCE(SUM(i.discount_amount), 0) > 0 ORDER BY o.order_date, o.id',
            [$entry['userId'], $since->format('Y-m-d H:i:s'), $orderDate]) as $o) {
            $discount = (int) round((float) $o['discount']);
            $gross = (int) round((float) $o['gross']);
            $bp = $gross > 0 ? intdiv($discount * 10000, $gross) : 0;
            if ($bp * 100 >= $capBp * $params['share'] || $discount * 100 >= $capAmount * $params['share']) {
                $near[] = $o + ['_discount' => $discount, '_gross' => $gross];
            }
        }
        if (count($near) < $params['count'] || (int) end($near)['id'] !== (int) $entry['subject']['id']) {
            return; // pas assez de remises proches du plafond, ou la vente lue n'en est pas une
        }
        $user = (int) $entry['userId'];
        $facts = ['count' => count($near), 'total' => array_sum(array_column($near, '_discount')), 'days' => $params['days'], 'share' => $params['share']];
        $evidence = array_map(fn (array $o) => [
            'kind' => LossEvidence::DISCOUNT, 'at' => LossAuditEntry::utc((string) $o['order_date']), 'refType' => 'order', 'refId' => (int) $o['id'],
            'sequence' => (int) $o['id'] === (int) $entry['subject']['id'] ? $entry['sequence'] : null, 'hash' => (int) $o['id'] === (int) $entry['subject']['id'] ? $entry['hash'] : null,
            'snapshot' => ['orderId' => (int) $o['id'], 'reference' => (string) $o['reference'], 'gross' => $o['_gross'], 'discount' => $o['_discount'], 'userId' => $user],
        ], $near);
        $duty = [['id' => $user, 'role' => 'seller']];
        $existing = $ctx->writer->latestForPerson($this->code(), $user, $since, $at);
        if ($existing !== null) {
            $ctx->writer->complete($existing, $facts, $duty, $facts['total'], $at, $evidence, !$ctx->isBackfill($entry['sequence']));

            return;
        }
        $ctx->writer->record([
            'rule_code' => $this->code(), 'variant' => LossFinding::VARIANT_PERSON, 'dedup_key' => $this->code() . ':person:' . $user . ':order:' . $entry['subject']['id'],
            'store_day' => $ctx->clock->day($at), 'occurred_from' => (string) $near[0]['order_date'], 'occurred_to' => $orderDate,
            'cash_register_id' => null, 'session_id' => null, 'amount_at_stake' => $facts['total'], 'currency' => $ctx->currency,
            'subject_type' => 'user_window', 'subject_id' => $user, 'facts' => $facts, 'on_duty' => $duty, 'backfill' => $ctx->isBackfill($entry['sequence']),
        ], $evidence);
    }
}
