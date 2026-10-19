<?php

namespace App\Dto;

use App\Services\CaisseService\CashRegisterException;

/**
 * Corps de POST /api/caisse/close (19/10/2026) : {cashRegisterId?, countedCash?, cashDetails?, discrepancyReason?,
 * keptFund?}. Montants en cents. Sans countedCash ni cashDetails : fermeture sans comptage (comportement d'avant,
 * un rapport Z est créé avec les champs de comptage à null).
 */
final class CloseCaisseInputDto
{
    public function __construct(
        public readonly ?int $cashRegisterId = null,
        public readonly ?int $countedCash = null,
        public readonly ?array $cashDetails = null,
        public readonly ?string $discrepancyReason = null,
        public readonly ?int $keptFund = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        $amount = function (string $key) use ($data): ?int {
            $value = $data[$key] ?? null;
            if ($value === null || $value === '') {
                return null;
            }
            if (!is_numeric($value) || (float) $value < 0 || (float) $value != (int) $value) {
                throw new CashRegisterException('INVALID_AMOUNT', sprintf('%s : montant en cents, entier positif ou nul, attendu', $key), 400);
            }

            return (int) $value;
        };
        $details = $data['cashDetails'] ?? null;
        if ($details !== null && !is_array($details)) {
            throw new CashRegisterException('INVALID_CASH_DETAILS', 'cashDetails : liste [{typeCash, nombreItems}] attendue', 400);
        }
        $reason = isset($data['discrepancyReason']) && is_scalar($data['discrepancyReason']) ? trim((string) $data['discrepancyReason']) : null;

        return new self(
            is_numeric($data['cashRegisterId'] ?? null) ? (int) $data['cashRegisterId'] : null,
            $amount('countedCash'),
            $details,
            $reason !== null && $reason !== '' ? mb_substr($reason, 0, 1000) : null,
            $amount('keptFund')
        );
    }
}
