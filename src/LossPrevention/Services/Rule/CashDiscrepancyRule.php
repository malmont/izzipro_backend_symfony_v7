<?php

namespace App\LossPrevention\Services\Rule;

use App\LossPrevention\Entity\LossEvidence;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Services\LossAnalysisContext;
use App\LossPrevention\Services\LossAuditEntry;
use App\LossPrevention\Services\LossRuleCatalog;

/**
 * CASH_DISCREPANCY (vague 1) : une clôture dont l'écart (compté − attendu) dépasse, en valeur absolue, le plus grand de l'écart toléré du site
 * et du plancher de la règle. Source : le rapport de clôture (`cash_register_report`, un par session), même définition que le bloc cashDesk des
 * statistiques. Une clôture sans comptage n'a pas d'écart : rien (règle réservée CLOSE_WITHOUT_COUNT).
 * La variante par personne est une règle à part (`CashShortageSeriesRule`) : son échec n'annule jamais le constat de la clôture.
 */
class CashDiscrepancyRule implements LossRuleInterface
{
    public const REPORT = 'SELECT z.id, z.number, z.cash_register_id, z.caisse_id, z.closed_at, z.closed_by_id, z.counted_cash, z.expected_cash, z.discrepancy, z.discrepancy_reason, z.kept_fund, z.bank_deposit, c.created_at AS opened_at'
        . ' FROM cash_register_report z JOIN caisse c ON c.id = z.caisse_id';

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
        $reportId = ($entry['subject']['type'] ?? null) === 'z_report' ? $entry['subject']['id'] : LossAuditEntry::intOrNull($entry['data']['reportId'] ?? null);
        $r = $reportId !== null ? $ctx->conn->fetchAssociative(self::REPORT . ' WHERE z.id = ?', [$reportId]) : false;
        if (!$r || $r['discrepancy'] === null) {
            return;
        }
        $cfg = $ctx->rules[$this->code()];
        $threshold = max($ctx->discrepancyThreshold, $cfg['params']['minAmount']);
        $discrepancy = (int) $r['discrepancy'];
        if (abs($discrepancy) <= $threshold) {
            return;
        }
        $closedAt = LossAuditEntry::utc((string) $r['closed_at']);
        $closer = $r['closed_by_id'] !== null ? (int) $r['closed_by_id'] : null;
        $onDuty = $closer !== null ? [['id' => $closer, 'role' => 'closer']] : [];
        foreach ($ctx->conn->fetchFirstColumn('SELECT DISTINCT user_caisse_id FROM transaction_caisse WHERE caisse_id = ? AND user_caisse_id IS NOT NULL AND transaction_type_id IN (1, 2)', [$r['caisse_id']]) as $seller) {
            $onDuty[] = ['id' => (int) $seller, 'role' => 'seller'];
        }
        $ctx->writer->record([
            'rule_code' => $this->code(), 'variant' => LossFinding::VARIANT_RULE, 'dedup_key' => $this->code() . ':z_report:' . $r['id'],
            // une session ancienne peut ne pas avoir d'heure d'ouverture : la fenêtre se réduit alors à la fermeture
            'store_day' => $ctx->clock->day($closedAt), 'occurred_from' => (string) ($r['opened_at'] ?? $r['closed_at']), 'occurred_to' => (string) $r['closed_at'],
            'cash_register_id' => (int) $r['cash_register_id'], 'session_id' => (int) $r['caisse_id'], 'amount_at_stake' => abs($discrepancy), 'currency' => $ctx->currency,
            'subject_type' => 'z_report', 'subject_id' => (int) $r['id'],
            'facts' => ['discrepancy' => $discrepancy, 'expectedCash' => (int) $r['expected_cash'], 'countedCash' => (int) $r['counted_cash'], 'reportNumber' => (int) $r['number'],
                'threshold' => $threshold, 'hasReason' => trim((string) $r['discrepancy_reason']) !== ''],
            'on_duty' => $onDuty, 'backfill' => $ctx->isBackfill($entry['sequence']),
        ], [self::evidence($r, $entry)]);
    }

    /**
     * @param array<string, mixed> $r ligne du rapport de clôture
     * @param array<string, mixed>|null $entry entrée du journal qui a créé le rapport, quand on l'a sous la main
     * @return array<string, mixed>
     */
    public static function evidence(array $r, ?array $entry): array
    {
        return [
            'kind' => LossEvidence::Z_REPORT, 'at' => LossAuditEntry::utc((string) $r['closed_at']), 'refType' => 'z_report', 'refId' => (int) $r['id'],
            'sequence' => $entry['sequence'] ?? null, 'hash' => $entry['hash'] ?? null,
            'snapshot' => [
                'reportNumber' => (int) $r['number'], 'cashRegisterId' => (int) $r['cash_register_id'], 'sessionId' => (int) $r['caisse_id'],
                'closedAt' => LossAuditEntry::iso(LossAuditEntry::utc((string) $r['closed_at'])), 'closedById' => $r['closed_by_id'] !== null ? (int) $r['closed_by_id'] : null,
                'expectedCash' => (int) $r['expected_cash'], 'countedCash' => LossAuditEntry::intOrNull($r['counted_cash']), 'discrepancy' => (int) $r['discrepancy'],
                // le motif saisi à la fermeture (texte libre) n'est pas recopié : la fiche le lit dans le rapport de clôture, par le renvoi
                'keptFund' => LossAuditEntry::intOrNull($r['kept_fund']), 'bankDeposit' => LossAuditEntry::intOrNull($r['bank_deposit']),
            ],
        ];
    }
}
