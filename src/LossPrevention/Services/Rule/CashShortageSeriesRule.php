<?php

namespace App\LossPrevention\Services\Rule;

use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Services\LossAnalysisContext;
use App\LossPrevention\Services\LossAuditEntry;
use App\LossPrevention\Services\LossRuleCatalog;

/**
 * Variante « par personne » de CASH_DISCREPANCY (vague 1) : N manques (écart négatif au-delà du seuil) fermés par le même compte en D jours.
 * Interrupteur à part, par pays et par site (`LossCountryPolicy`, réglages) : permise aux États-Unis, allumée d'office en Floride.
 * Un constat par fenêtre et par compte (sujet `user_window`), complété tant que la fenêtre court ; la recherche du constat à compléter est
 * bornée à la fenêtre de la clôture lue, pour qu'un rejeu d'une clôture ancienne ne touche jamais un constat né plus tard.
 * Règle à part de `CashDiscrepancyRule` : chacune a son point de reprise, l'échec de l'une n'annule pas ce que l'autre a écrit.
 */
class CashShortageSeriesRule implements LossRuleInterface
{
    public function code(): string
    {
        return LossRuleCatalog::CASH_DISCREPANCY;
    }

    public function types(): array
    {
        return ['Z_REPORT_CREATED'];
    }

    public function handle(array $entry, LossAnalysisContext $ctx): void
    {
        $cfg = $ctx->rules[$this->code()];
        if (!$cfg['personEnabled']) {
            return;
        }
        $reportId = ($entry['subject']['type'] ?? null) === 'z_report' ? $entry['subject']['id'] : LossAuditEntry::intOrNull($entry['data']['reportId'] ?? null);
        $r = $reportId !== null ? $ctx->conn->fetchAssociative(CashDiscrepancyRule::REPORT . ' WHERE z.id = ?', [$reportId]) : false;
        $threshold = max($ctx->discrepancyThreshold, $cfg['params']['minAmount']);
        if (!$r || $r['discrepancy'] === null || $r['closed_by_id'] === null || (int) $r['discrepancy'] >= -$threshold) {
            return;
        }
        $closer = (int) $r['closed_by_id'];
        $closedAt = LossAuditEntry::utc((string) $r['closed_at']);
        $since = $closedAt->modify('-' . $cfg['personParams']['days'] . ' days');
        $rows = $ctx->conn->fetchAllAssociative(CashDiscrepancyRule::REPORT . ' WHERE z.closed_by_id = ? AND z.closed_at > ? AND z.closed_at <= ? AND z.discrepancy < ? ORDER BY z.closed_at, z.id',
            [$closer, $since->format('Y-m-d H:i:s'), (string) $r['closed_at'], -$threshold]);
        if (count($rows) < $cfg['personParams']['count']) {
            return;
        }
        $facts = ['count' => count($rows), 'total' => array_sum(array_map(fn ($x) => abs((int) $x['discrepancy']), $rows)), 'days' => $cfg['personParams']['days'], 'threshold' => $threshold];
        $evidence = array_map(fn ($x) => CashDiscrepancyRule::evidence($x, (int) $x['id'] === (int) $r['id'] ? $entry : null), $rows);
        $duty = [['id' => $closer, 'role' => 'closer']];
        $existing = $ctx->writer->latestForPerson($this->code(), $closer, $since, $closedAt);
        if ($existing !== null) {
            $ctx->writer->complete($existing, $facts, $duty, $facts['total'], $closedAt, $evidence, !$ctx->isBackfill($entry['sequence']));

            return;
        }
        $ctx->writer->record([
            'rule_code' => $this->code(), 'variant' => LossFinding::VARIANT_PERSON, 'dedup_key' => $this->code() . ':person:' . $closer . ':z_report:' . $r['id'],
            'store_day' => $ctx->clock->day($closedAt), 'occurred_from' => (string) $rows[0]['closed_at'], 'occurred_to' => (string) $r['closed_at'],
            'cash_register_id' => null, 'session_id' => null, 'amount_at_stake' => $facts['total'], 'currency' => $ctx->currency,
            'subject_type' => 'user_window', 'subject_id' => $closer, 'facts' => $facts, 'on_duty' => $duty, 'backfill' => $ctx->isBackfill($entry['sequence']),
        ], $evidence);
    }
}
