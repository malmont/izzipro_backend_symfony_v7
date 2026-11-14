<?php

namespace App\LossPrevention\Services\Rule;

use App\LossPrevention\Entity\LossEvidence;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Services\LossAnalysisContext;
use App\LossPrevention\Services\LossAuditEntry;
use App\LossPrevention\Services\LossRuleCatalog;

/**
 * DISCOUNT_PATTERN (vague 2) : N ventes remisées au motif « Autre », ou sans motif, dans une même session de caisse. Source : les entrées
 * DISCOUNT_APPLIED du journal de caisse (une par vente remisée : brut, total des remises, remise de ticket et remises de ligne avec leur code
 * de motif). Un constat par session, complété à chaque vente suivante. La variante par personne est une règle à part (`DiscountSeriesRule`).
 */
class DiscountPatternRule implements LossRuleInterface
{
    /** Codes de motif fourre-tout (le code d'un motif est son libellé en minuscules ; « Autre » est le motif par défaut de la caisse) */
    public const VAGUE = ['autre', 'other'];

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
        if ($entry['sessionId'] === null) {
            return;
        }
        $perSession = $ctx->rules[$this->code()]['params']['perSession'];
        $vague = array_values(array_filter(LossAuditEntry::ofSession($ctx->conn, 'DISCOUNT_APPLIED', $entry['sessionId'], $entry['cashRegisterId'], $entry['sequence'], $ctx->floorSequence),
            fn (array $e) => self::isVague($e['data'])));
        if (!self::isVague($entry['data']) || count($vague) < $perSession) {
            return;
        }
        $onDuty = [];
        $evidence = [];
        foreach ($vague as $e) {
            $approver = LossAuditEntry::approvedById($e['data']);
            foreach (['operator' => $e['userId'], 'approver' => $approver] as $role => $id) {
                if ($id !== null) {
                    $onDuty[] = ['id' => $id, 'role' => $role];
                }
            }
            $evidence[] = self::evidence($e, $approver);
        }
        $total = array_sum(array_map(fn (array $e) => (int) ($e['data']['total'] ?? 0), $vague));
        $ctx->writer->record([
            'rule_code' => $this->code(), 'variant' => LossFinding::VARIANT_RULE, 'dedup_key' => $this->code() . ':session:' . $entry['sessionId'],
            'store_day' => $ctx->clock->day($vague[0]['occurredAt']), 'occurred_from' => $vague[0]['occurredAt']->format('Y-m-d H:i:s'), 'occurred_to' => $entry['occurredAt']->format('Y-m-d H:i:s'),
            'cash_register_id' => $entry['cashRegisterId'], 'session_id' => $entry['sessionId'], 'amount_at_stake' => $total, 'currency' => $ctx->currency,
            'subject_type' => 'session', 'subject_id' => $entry['sessionId'], 'facts' => ['count' => count($vague), 'perSession' => $perSession, 'total' => $total],
            'on_duty' => $onDuty, 'backfill' => $ctx->isBackfill($entry['sequence']),
        ], $evidence);
    }

    /**
     * Codes de motif des remises d'une vente (remise de ticket et remises de ligne) ; une remise sans code compte pour `null`.
     *
     * @param array<string, mixed> $data données d'une entrée DISCOUNT_APPLIED
     * @return list<?string>
     */
    public static function reasonCodes(array $data): array
    {
        $codes = [];
        if (is_array($data['ticket'] ?? null)) {
            $codes[] = isset($data['ticket']['reasonCode']) && $data['ticket']['reasonCode'] !== '' ? strtolower((string) $data['ticket']['reasonCode']) : null;
        }
        foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $line) {
            foreach (is_array($line['discounts'] ?? null) ? $line['discounts'] : [] as $d) {
                if (($d['scope'] ?? 'line') === 'ticket') {
                    continue; // part de la remise de ticket répartie sur la ligne : déjà comptée
                }
                $codes[] = isset($d['reasonCode']) && $d['reasonCode'] !== '' ? strtolower((string) $d['reasonCode']) : null;
            }
        }

        return $codes;
    }

    /** Une vente est « vague » si une de ses remises est au motif fourre-tout, ou sans motif */
    public static function isVague(array $data): bool
    {
        foreach (self::reasonCodes($data) as $code) {
            if ($code === null || in_array($code, self::VAGUE, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $e entrée DISCOUNT_APPLIED décodée
     * @return array<string, mixed>
     */
    private static function evidence(array $e, ?int $approver): array
    {
        return [
            'kind' => LossEvidence::DISCOUNT, 'at' => $e['occurredAt'], 'refType' => 'pos_audit', 'refId' => $e['id'], 'sequence' => $e['sequence'], 'hash' => $e['hash'],
            'snapshot' => [
                'orderId' => $e['subject']['id'] ?? null, 'reference' => isset($e['data']['reference']) ? (string) $e['data']['reference'] : null,
                'gross' => LossAuditEntry::intOrNull($e['data']['gross'] ?? null), 'discount' => LossAuditEntry::intOrNull($e['data']['total'] ?? null),
                'reasonCodes' => array_values(array_unique(array_map(fn (?string $c) => $c ?? '', self::reasonCodes($e['data'])))),
                'userId' => $e['userId'], 'approvedById' => $approver,
            ],
        ];
    }
}
