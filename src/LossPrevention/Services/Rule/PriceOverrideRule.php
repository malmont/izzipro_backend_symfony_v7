<?php

namespace App\LossPrevention\Services\Rule;

use App\LossPrevention\Entity\LossEvidence;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Services\LossAnalysisContext;
use App\LossPrevention\Services\LossAuditEntry;
use App\LossPrevention\Services\LossRuleCatalog;

/**
 * PRICE_OVERRIDE (vague 2) : N baisses de prix à la main dans une même session de caisse. Source : les entrées PRICE_CHANGED du journal de
 * caisse (une par ligne dont le prix a été remplacé : prix du catalogue, prix saisi, écart, code de motif). Une hausse ne compte pas.
 * Un constat par session, complété à chaque baisse suivante ; le montant en jeu est la somme des baisses.
 */
class PriceOverrideRule implements LossRuleInterface
{
    public function code(): string
    {
        return LossRuleCatalog::PRICE_OVERRIDE;
    }

    public function types(): array
    {
        return ['PRICE_CHANGED'];
    }

    public function handle(array $entry, LossAnalysisContext $ctx): void
    {
        if ($entry['sessionId'] === null || (int) ($entry['data']['difference'] ?? 0) <= 0) {
            return;
        }
        $perSession = $ctx->rules[$this->code()]['params']['perSession'];
        $drops = array_values(array_filter(LossAuditEntry::ofSession($ctx->conn, 'PRICE_CHANGED', $entry['sessionId'], $entry['cashRegisterId'], $entry['sequence'], $ctx->floorSequence),
            fn (array $e) => (int) ($e['data']['difference'] ?? 0) > 0));
        if (count($drops) < $perSession) {
            return;
        }
        $onDuty = [];
        $evidence = [];
        foreach ($drops as $e) {
            $approver = LossAuditEntry::approvedById($e['data']);
            foreach (['operator' => $e['userId'], 'approver' => $approver] as $role => $id) {
                if ($id !== null) {
                    $onDuty[] = ['id' => $id, 'role' => $role];
                }
            }
            // le motif écrit (texte libre) n'est pas recopié : la fiche le lit dans la ligne de vente, par `orderItemId`
            $evidence[] = [
                'kind' => LossEvidence::PRICE_CHANGE, 'at' => $e['occurredAt'], 'refType' => 'pos_audit', 'refId' => $e['id'], 'sequence' => $e['sequence'], 'hash' => $e['hash'],
                'snapshot' => [
                    'orderId' => $e['subject']['id'] ?? null, 'orderItemId' => LossAuditEntry::intOrNull($e['data']['orderItemId'] ?? null),
                    'productVariantId' => LossAuditEntry::intOrNull($e['data']['productVariantId'] ?? null), 'reference' => isset($e['data']['reference']) ? (string) $e['data']['reference'] : null,
                    'article' => isset($e['data']['name']) ? (string) $e['data']['name'] : null, 'quantity' => LossAuditEntry::intOrNull($e['data']['quantity'] ?? null),
                    'catalogPrice' => LossAuditEntry::intOrNull($e['data']['before'] ?? null), 'manualPrice' => LossAuditEntry::intOrNull($e['data']['after'] ?? null),
                    'difference' => (int) $e['data']['difference'], 'reasonCode' => isset($e['data']['reason']['code']) ? (string) $e['data']['reason']['code'] : null,
                    'userId' => $e['userId'], 'approvedById' => $approver,
                ],
            ];
        }
        $total = array_sum(array_map(fn (array $e) => (int) $e['data']['difference'], $drops));
        $ctx->writer->record([
            'rule_code' => $this->code(), 'variant' => LossFinding::VARIANT_RULE, 'dedup_key' => $this->code() . ':session:' . $entry['sessionId'],
            'store_day' => $ctx->clock->day($drops[0]['occurredAt']), 'occurred_from' => $drops[0]['occurredAt']->format('Y-m-d H:i:s'), 'occurred_to' => $entry['occurredAt']->format('Y-m-d H:i:s'),
            'cash_register_id' => $entry['cashRegisterId'], 'session_id' => $entry['sessionId'], 'amount_at_stake' => $total, 'currency' => $ctx->currency,
            'subject_type' => 'session', 'subject_id' => $entry['sessionId'], 'facts' => ['count' => count($drops), 'perSession' => $perSession, 'total' => $total],
            'on_duty' => $onDuty, 'backfill' => $ctx->isBackfill($entry['sequence']),
        ], $evidence);
    }
}
