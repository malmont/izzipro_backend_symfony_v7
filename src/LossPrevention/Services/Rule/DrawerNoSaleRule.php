<?php

namespace App\LossPrevention\Services\Rule;

use App\LossPrevention\Entity\LossEvidence;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Services\LossAnalysisContext;
use App\LossPrevention\Services\LossAuditEntry;
use App\LossPrevention\Services\LossRuleCatalog;

/**
 * DRAWER_NO_SALE (vague 1) : plus de N ouvertures du tiroir sans vente dans une session de caisse. Source : les entrées DRAWER_OPENED du
 * journal de caisse (motif saisi, qui a ouvert, qui a validé). Un constat par session, complété à chaque ouverture suivante.
 */
class DrawerNoSaleRule implements LossRuleInterface
{
    public function code(): string
    {
        return LossRuleCatalog::DRAWER_NO_SALE;
    }

    public function types(): array
    {
        return ['DRAWER_OPENED'];
    }

    public function handle(array $entry, LossAnalysisContext $ctx): void
    {
        if ($entry['sessionId'] === null) {
            return;
        }
        $max = $ctx->rules[$this->code()]['params']['maxPerSession'];
        $openings = LossAuditEntry::ofSession($ctx->conn, 'DRAWER_OPENED', $entry['sessionId'], $entry['cashRegisterId'], $entry['sequence'], $ctx->floorSequence);
        if (count($openings) <= $max) {
            return;
        }
        $onDuty = [];
        $evidence = [];
        foreach ($openings as $o) {
            $approver = LossAuditEntry::approvedById($o['data']);
            if ($o['userId'] !== null) {
                $onDuty[] = ['id' => $o['userId'], 'role' => 'operator'];
            }
            if ($approver !== null) {
                $onDuty[] = ['id' => $approver, 'role' => 'approver'];
            }
            $evidence[] = [
                'kind' => LossEvidence::DRAWER, 'at' => $o['occurredAt'], 'refType' => 'pos_audit', 'refId' => $o['id'], 'sequence' => $o['sequence'], 'hash' => $o['hash'],
                // le motif saisi par le caissier (texte libre) n'est pas recopié : la fiche le lit dans le journal de caisse, par le renvoi
                'snapshot' => ['userId' => $o['userId'], 'approvedById' => $approver],
            ];
        }
        $first = $openings[0]['occurredAt'];
        $ctx->writer->record([
            'rule_code' => $this->code(), 'variant' => LossFinding::VARIANT_RULE, 'dedup_key' => $this->code() . ':session:' . $entry['sessionId'],
            'store_day' => $ctx->clock->day($first), 'occurred_from' => $first->format('Y-m-d H:i:s'), 'occurred_to' => $entry['occurredAt']->format('Y-m-d H:i:s'),
            'cash_register_id' => $entry['cashRegisterId'], 'session_id' => $entry['sessionId'], 'amount_at_stake' => null, 'currency' => $ctx->currency,
            'subject_type' => 'session', 'subject_id' => $entry['sessionId'], 'facts' => ['count' => count($openings), 'max' => $max],
            'on_duty' => $onDuty, 'backfill' => $ctx->isBackfill($entry['sequence']),
        ], $evidence);
    }
}
