<?php

namespace App\LossPrevention\Services\Rule;

use App\LossPrevention\Entity\LossEvidence;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Services\LossAnalysisContext;
use App\LossPrevention\Services\LossAuditEntry;
use App\LossPrevention\Services\LossRuleCatalog;

/**
 * VOID_AFTER_SALE (vague 1) : annulation complète d'une vente au comptoir (entrée REFUND du journal, `kind: cancellation`).
 * - « rapide » : la vente avait une part en espèces et elle est annulée dans les N minutes ; un constat par vente ;
 * - « série » : N annulations dans la même session de caisse ; un constat par session, complété à chaque annulation suivante.
 * Date de la vente : `order.order_date` (heure réelle, même pour une vente hors ligne synchronisée plus tard) ; date de l'annulation :
 * `order.canceled_at`, ou l'heure de l'entrée du journal pour une annulation d'avant PAYS-1b. Un retour (commande de type 2) n'est pas une
 * annulation. Le serveur ne voit que les ventes enregistrées : une ligne retirée du panier avant paiement ne laisse aucune trace.
 */
class VoidAfterSaleRule implements LossRuleInterface
{
    public function code(): string
    {
        return LossRuleCatalog::VOID_AFTER_SALE;
    }

    public function types(): array
    {
        return ['REFUND'];
    }

    public function handle(array $entry, LossAnalysisContext $ctx): void
    {
        if (($entry['data']['kind'] ?? null) !== 'cancellation' || ($entry['subject']['type'] ?? null) !== 'order' || $entry['subject']['id'] === null) {
            return;
        }
        $params = $ctx->rules[$this->code()]['params'];
        $cancel = $this->cancellation($entry, $ctx);
        if ($cancel === null) {
            return;
        }
        // Annulation rapide d'une vente qui avait une part en espèces
        $delay = $cancel['canceledAt']->getTimestamp() - $cancel['soldAt']->getTimestamp();
        if ($cancel['cashPart'] > 0 && $delay >= 0 && $delay <= $params['minutes'] * 60) {
            $ctx->writer->record([
                'rule_code' => $this->code(), 'variant' => LossFinding::VARIANT_RULE, 'dedup_key' => $this->code() . ':order:' . $cancel['orderId'],
                'store_day' => $ctx->clock->day($cancel['canceledAt']), 'occurred_from' => $cancel['soldAt']->format('Y-m-d H:i:s'), 'occurred_to' => $cancel['canceledAt']->format('Y-m-d H:i:s'),
                'cash_register_id' => $entry['cashRegisterId'], 'session_id' => $entry['sessionId'], 'amount_at_stake' => $cancel['total'], 'currency' => $ctx->currency,
                'subject_type' => 'order', 'subject_id' => $cancel['orderId'],
                'facts' => ['case' => 'quick', 'delayMinutes' => intdiv($delay, 60), 'minutes' => $params['minutes'], 'total' => $cancel['total'], 'cashPart' => $cancel['cashPart']],
                'on_duty' => $cancel['onDuty'], 'backfill' => $ctx->isBackfill($entry['sequence']),
            ], [$cancel['sale'], $cancel['evidence']]);
        }
        // Série d'annulations dans la session de caisse où elles sont faites
        if ($entry['sessionId'] === null) {
            return;
        }
        $series = [];
        foreach (LossAuditEntry::ofSession($ctx->conn, 'REFUND', $entry['sessionId'], $entry['cashRegisterId'], $entry['sequence'], $ctx->floorSequence) as $other) {
            if (($other['data']['kind'] ?? null) === 'cancellation' && ($other['subject']['type'] ?? null) === 'order' && $other['subject']['id'] !== null) {
                $c = $other['id'] === $entry['id'] ? $cancel : $this->cancellation($other, $ctx);
                if ($c !== null) {
                    $series[] = $c;
                }
            }
        }
        if (count($series) < $params['perSession']) {
            return;
        }
        $ctx->writer->record([
            'rule_code' => $this->code(), 'variant' => LossFinding::VARIANT_RULE, 'dedup_key' => $this->code() . ':session:' . $entry['sessionId'],
            'store_day' => $ctx->clock->day($series[0]['canceledAt']), 'occurred_from' => $series[0]['canceledAt']->format('Y-m-d H:i:s'), 'occurred_to' => $cancel['canceledAt']->format('Y-m-d H:i:s'),
            'cash_register_id' => $entry['cashRegisterId'], 'session_id' => $entry['sessionId'], 'amount_at_stake' => array_sum(array_column($series, 'total')), 'currency' => $ctx->currency,
            'subject_type' => 'session', 'subject_id' => $entry['sessionId'],
            'facts' => ['case' => 'series', 'count' => count($series), 'perSession' => $params['perSession'], 'total' => array_sum(array_column($series, 'total'))],
            'on_duty' => array_merge(...array_column($series, 'onDuty')), 'backfill' => $ctx->isBackfill($entry['sequence']),
        ], array_column($series, 'evidence'));
    }

    /**
     * Une annulation, relue dans la vente et ses paiements.
     *
     * @param array<string, mixed> $entry entrée REFUND `kind: cancellation`
     * @return array{orderId: int, total: int, cashPart: int, soldAt: \DateTimeImmutable, canceledAt: \DateTimeImmutable, onDuty: list<array{id: int, role: string}>, sale: array<string, mixed>, evidence: array<string, mixed>}|null
     */
    private function cancellation(array $entry, LossAnalysisContext $ctx): ?array
    {
        $orderId = (int) $entry['subject']['id'];
        $order = $ctx->conn->fetchAssociative('SELECT id, reference, order_date, canceled_at, total_amount, user_id_id FROM "order" WHERE id = ?', [$orderId]);
        if (!$order || $order['order_date'] === null) {
            return null;
        }
        $total = (int) round((float) $order['total_amount']);
        if ($total === 0) { // annulation d'avant PAYS-1b : la vente a été remise à 0, le montant d'origine est dans l'entrée du journal
            $total = LossAuditEntry::intOrNull($entry['data']['before']['totalAmount'] ?? null) ?? LossAuditEntry::intOrNull($entry['data']['refundAmount'] ?? null) ?? 0;
        }
        $cashPart = (int) round((float) $ctx->conn->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_payment_id = ? AND payment_method_id = 2 AND amount > 0', [$orderId]));
        $soldAt = LossAuditEntry::utc((string) $order['order_date']);
        $canceledAt = $order['canceled_at'] !== null ? LossAuditEntry::utc((string) $order['canceled_at']) : $entry['occurredAt'];
        $seller = $order['user_id_id'] !== null ? (int) $order['user_id_id'] : null;
        $approver = LossAuditEntry::approvedById($entry['data']);
        $onDuty = [];
        foreach (['seller' => $seller, 'operator' => $entry['userId'], 'approver' => $approver] as $role => $id) {
            if ($id !== null) {
                $onDuty[] = ['id' => $id, 'role' => $role];
            }
        }

        return [
            'orderId' => $orderId, 'total' => $total, 'cashPart' => $cashPart, 'soldAt' => $soldAt, 'canceledAt' => $canceledAt, 'onDuty' => $onDuty,
            'sale' => ['kind' => LossEvidence::ORDER, 'at' => $soldAt, 'refType' => 'order', 'refId' => $orderId,
                'snapshot' => ['reference' => (string) $order['reference'], 'soldAt' => LossAuditEntry::iso($soldAt), 'total' => $total, 'cashPart' => $cashPart, 'sellerId' => $seller]],
            'evidence' => ['kind' => LossEvidence::CREDIT_NOTE, 'at' => $canceledAt, 'refType' => 'pos_audit', 'refId' => $entry['id'], 'sequence' => $entry['sequence'], 'hash' => $entry['hash'],
                'snapshot' => ['orderId' => $orderId, 'reference' => (string) $order['reference'], 'soldAt' => LossAuditEntry::iso($soldAt), 'canceledAt' => LossAuditEntry::iso($canceledAt),
                    'total' => $total, 'creditNoteNumber' => LossAuditEntry::intOrNull($entry['data']['creditNoteNumber'] ?? null),
                    'refundAmount' => LossAuditEntry::intOrNull($entry['data']['refundAmount'] ?? null), 'cashRefund' => LossAuditEntry::intOrNull($entry['data']['cashRefund'] ?? null),
                    'canceledById' => $entry['userId'], 'approvedById' => $approver]],
        ];
    }
}
