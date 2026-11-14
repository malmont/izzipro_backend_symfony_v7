<?php

namespace App\LossPrevention\Services\Rule;

use App\LossPrevention\Entity\LossEvidence;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Services\LossAnalysisContext;
use App\LossPrevention\Services\LossAuditEntry;
use App\LossPrevention\Services\LossRuleCatalog;

/**
 * REFUND_PATTERN (vague 2) : un retour de caisse (entrée REFUND du journal, `kind: return` ; table `pos_return`) qui sort de l'ordinaire.
 * - « sans ticket » : le retour n'est rattaché à aucune vente d'origine ;
 * - « espèces pour une vente sans espèces » : de l'argent est rendu en espèces alors que la vente d'origine n'avait aucune part en espèces.
 * Les deux cas sont interdits par défaut dans les réglages de la caisse : ils n'arrivent que si le site les a autorisés. Un constat par retour.
 * La variante par personne est une règle à part (`RefundSeriesRule`).
 */
class RefundPatternRule implements LossRuleInterface
{
    public const RETURN = 'SELECT r.id, r.order_id, r.return_order_id, r.cash_register_id, r.caisse_id, r.user_id, r.reason_code, r.restock, r.refund_method, r.amount, r.cash_refund, r.card_refund, r.created_at,'
        . ' o.reference AS return_reference, s.user_id_id AS seller_id FROM pos_return r LEFT JOIN "order" o ON o.id = r.return_order_id LEFT JOIN "order" s ON s.id = r.order_id';

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
        $r = self::returnOf($entry, $ctx);
        if ($r === null) {
            return;
        }
        $case = null;
        if ($r['order_id'] === null) {
            $case = 'no_receipt';
        } elseif ((int) $r['cash_refund'] > 0
            && (int) round((float) $ctx->conn->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_payment_id = ? AND payment_method_id = 2 AND amount > 0', [$r['order_id']])) === 0) {
            $case = 'cash_for_card';
        }
        if ($case === null) {
            return;
        }
        $at = LossAuditEntry::utc((string) $r['created_at']);
        $ctx->writer->record([
            'rule_code' => $this->code(), 'variant' => LossFinding::VARIANT_RULE, 'dedup_key' => $this->code() . ':return:' . $r['id'],
            'store_day' => $ctx->clock->day($at), 'occurred_from' => (string) $r['created_at'], 'occurred_to' => (string) $r['created_at'],
            'cash_register_id' => $r['cash_register_id'] !== null ? (int) $r['cash_register_id'] : null, 'session_id' => $r['caisse_id'] !== null ? (int) $r['caisse_id'] : null,
            'amount_at_stake' => (int) $r['amount'], 'currency' => $ctx->currency, 'subject_type' => 'return', 'subject_id' => (int) $r['id'],
            'facts' => ['case' => $case, 'amount' => (int) $r['amount'], 'cashRefund' => (int) $r['cash_refund']],
            'on_duty' => self::onDuty($r, $entry), 'backfill' => $ctx->isBackfill($entry['sequence']),
        ], [self::evidence($r, $entry)]);
    }

    /**
     * Le retour d'une entrée REFUND `kind: return`, relu dans `pos_return`.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    public static function returnOf(array $entry, LossAnalysisContext $ctx): ?array
    {
        $returnId = ($entry['data']['kind'] ?? null) === 'return' ? LossAuditEntry::intOrNull($entry['data']['returnId'] ?? null) : null;

        return $returnId !== null ? ($ctx->conn->fetchAssociative(self::RETURN . ' WHERE r.id = ?', [$returnId]) ?: null) : null;
    }

    /**
     * @param array<string, mixed> $r ligne du retour
     * @param array<string, mixed>|null $entry entrée du journal de caisse du retour, quand on l'a sous la main
     * @return list<array{id: int, role: string}>
     */
    public static function onDuty(array $r, ?array $entry): array
    {
        $out = [];
        foreach (['operator' => $r['user_id'], 'seller' => $r['seller_id'], 'approver' => $entry !== null ? LossAuditEntry::approvedById($entry['data']) : null] as $role => $id) {
            if ($id !== null) {
                $out[] = ['id' => (int) $id, 'role' => $role];
            }
        }

        return $out;
    }

    /**
     * Le motif écrit du retour (texte libre) n'est pas recopié : la fiche le lit dans `pos_return`, par le renvoi.
     *
     * @param array<string, mixed> $r
     * @param array<string, mixed>|null $entry
     * @return array<string, mixed>
     */
    public static function evidence(array $r, ?array $entry): array
    {
        return [
            'kind' => LossEvidence::RETURN, 'at' => LossAuditEntry::utc((string) $r['created_at']), 'refType' => 'pos_return', 'refId' => (int) $r['id'],
            'sequence' => $entry['sequence'] ?? null, 'hash' => $entry['hash'] ?? null,
            'snapshot' => [
                'returnId' => (int) $r['id'], 'reference' => $r['return_reference'] !== null ? (string) $r['return_reference'] : null,
                'originalOrderId' => $r['order_id'] !== null ? (int) $r['order_id'] : null, 'returnedAt' => LossAuditEntry::iso(LossAuditEntry::utc((string) $r['created_at'])),
                'amount' => (int) $r['amount'], 'cashRefund' => (int) $r['cash_refund'], 'cardRefund' => (int) $r['card_refund'], 'refundMethod' => (string) $r['refund_method'],
                'reasonCode' => $r['reason_code'] !== null ? (string) $r['reason_code'] : null, 'restock' => (bool) $r['restock'],
                'userId' => $r['user_id'] !== null ? (int) $r['user_id'] : null, 'approvedById' => $entry !== null ? LossAuditEntry::approvedById($entry['data']) : null,
            ],
        ];
    }
}
