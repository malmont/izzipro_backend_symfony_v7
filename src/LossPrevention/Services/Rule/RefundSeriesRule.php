<?php

namespace App\LossPrevention\Services\Rule;

use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Services\LossAnalysisContext;
use App\LossPrevention\Services\LossAuditEntry;
use App\LossPrevention\Services\LossRuleCatalog;

/**
 * Variante « par personne » de REFUND_PATTERN (vague 2) : N retours faits par le même compte dans une même journée du magasin. Interrupteur à
 * part, par pays et par site. Un constat par compte et par journée (la clé porte la journée : rejouer n'en ouvre pas un second).
 */
class RefundSeriesRule implements LossRuleInterface
{
    public function code(): string
    {
        return LossRuleCatalog::REFUND_PATTERN;
    }

    public function types(): array
    {
        return ['REFUND'];
    }

    public function handle(array $entry, LossAnalysisContext $ctx): void
    {
        $cfg = $ctx->rules[$this->code()];
        if (!$cfg['personEnabled']) {
            return;
        }
        $r = RefundPatternRule::returnOf($entry, $ctx);
        if ($r === null || $r['user_id'] === null) {
            return;
        }
        $user = (int) $r['user_id'];
        $at = LossAuditEntry::utc((string) $r['created_at']);
        $day = $ctx->clock->day($at);
        [$start] = $ctx->clock->range($day, $day);
        $rows = $ctx->conn->fetchAllAssociative(RefundPatternRule::RETURN . ' WHERE r.user_id = ? AND r.created_at >= ? AND r.created_at <= ? ORDER BY r.created_at, r.id',
            [$user, $start->format('Y-m-d H:i:s'), (string) $r['created_at']]);
        if (count($rows) < $cfg['personParams']['count']) {
            return;
        }
        $total = array_sum(array_map(fn ($x) => (int) $x['amount'], $rows));
        $ctx->writer->record([
            'rule_code' => $this->code(), 'variant' => LossFinding::VARIANT_PERSON, 'dedup_key' => $this->code() . ':person:' . $user . ':day:' . $day,
            'store_day' => $day, 'occurred_from' => (string) $rows[0]['created_at'], 'occurred_to' => (string) $r['created_at'],
            'cash_register_id' => null, 'session_id' => null, 'amount_at_stake' => $total, 'currency' => $ctx->currency,
            'subject_type' => 'user_window', 'subject_id' => $user, 'facts' => ['count' => count($rows), 'total' => $total, 'days' => 1],
            'on_duty' => [['id' => $user, 'role' => 'operator']], 'backfill' => $ctx->isBackfill($entry['sequence']),
        ], array_map(fn ($x) => RefundPatternRule::evidence($x, (int) $x['id'] === (int) $r['id'] ? $entry : null), $rows));
    }
}
