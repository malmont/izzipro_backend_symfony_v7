<?php

namespace App\LossPrevention\Services\Rule;

use App\LossPrevention\Entity\LossEvidence;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Services\LossAnalysisContext;
use App\LossPrevention\Services\LossAuditEntry;
use App\LossPrevention\Services\LossRuleCatalog;

/**
 * APPROVAL_PATTERN (vague 2) : N validations d'un responsable refusées (PIN faux, PIN bloqué, compte qui n'a pas le droit de valider, trop
 * d'essais) sur une même tablette dans une même journée du magasin. Source : les entrées APPROVAL_FAILED du journal de caisse.
 *
 * Pourquoi « par tablette et par journée » et non « par session » : une validation refusée est journalisée hors de toute transaction de
 * caisse, sans session ni poste ; seule la tablette (le terminal) y est connue. Une demande mal formée ou un responsable sans PIN (réglage à
 * faire) ne comptent pas. Un constat par tablette et par journée, complété à chaque refus suivant.
 */
class ApprovalPatternRule implements LossRuleInterface
{
    /** Raisons d'un refus qui ne disent rien d'une tentative (demande mal formée, PIN jamais créé) */
    private const IGNORED = ['VALIDATION_FAILED', 'PIN_NOT_SET'];

    public function code(): string
    {
        return LossRuleCatalog::APPROVAL_PATTERN;
    }

    public function types(): array
    {
        return ['APPROVAL_FAILED'];
    }

    public function handle(array $entry, LossAnalysisContext $ctx): void
    {
        if ($entry['terminalId'] === null || in_array($entry['data']['reason'] ?? null, self::IGNORED, true)) {
            return;
        }
        $perDay = $ctx->rules[$this->code()]['params']['perDay'];
        $day = $ctx->clock->day($entry['occurredAt']);
        [$start, $end] = $ctx->clock->range($day, $day);
        // les refus sont rares : la lecture passe par l'index du type, bornée à la journée, à la tablette et au plancher de l'analyse
        $refusals = array_values(array_filter(array_map([LossAuditEntry::class, 'fromRow'], $ctx->conn->fetchAllAssociative(
            'SELECT ' . LossAuditEntry::COLUMNS . " FROM pos_audit_log WHERE type = 'APPROVAL_FAILED' AND terminal_id = ? AND occurred_at >= ? AND occurred_at < ? AND sequence <= ? AND sequence > ? ORDER BY sequence",
            [$entry['terminalId'], $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $entry['sequence'], $ctx->floorSequence])),
            fn (array $e) => !in_array($e['data']['reason'] ?? null, self::IGNORED, true)));
        if (count($refusals) < $perDay) {
            return;
        }
        $onDuty = [];
        $evidence = [];
        $register = null;
        foreach ($refusals as $e) {
            if ($e['userId'] !== null) {
                $onDuty[] = ['id' => $e['userId'], 'role' => 'operator'];
            }
            $register ??= LossAuditEntry::intOrNull($e['data']['context']['cashRegisterId'] ?? null);
            $evidence[] = [
                'kind' => LossEvidence::APPROVAL, 'at' => $e['occurredAt'], 'refType' => 'pos_audit', 'refId' => $e['id'], 'sequence' => $e['sequence'], 'hash' => $e['hash'],
                'snapshot' => ['action' => isset($e['data']['action']) ? (string) $e['data']['action'] : null, 'refusal' => isset($e['data']['reason']) ? (string) $e['data']['reason'] : null,
                    'userId' => $e['userId'], 'managerId' => LossAuditEntry::intOrNull($e['data']['managerId'] ?? null), 'terminalId' => $e['terminalId']],
            ];
        }
        $ctx->writer->record([
            'rule_code' => $this->code(), 'variant' => LossFinding::VARIANT_RULE, 'dedup_key' => $this->code() . ':terminal:' . $entry['terminalId'] . ':day:' . $day,
            'store_day' => $day, 'occurred_from' => $refusals[0]['occurredAt']->format('Y-m-d H:i:s'), 'occurred_to' => $entry['occurredAt']->format('Y-m-d H:i:s'),
            'cash_register_id' => $register, 'session_id' => null, 'amount_at_stake' => null, 'currency' => $ctx->currency,
            'subject_type' => 'terminal', 'subject_id' => $entry['terminalId'], 'facts' => ['count' => count($refusals), 'perDay' => $perDay],
            'on_duty' => $onDuty, 'backfill' => $ctx->isBackfill($entry['sequence']),
        ], $evidence);
    }
}
