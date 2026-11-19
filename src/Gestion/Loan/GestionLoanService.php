<?php

namespace App\Gestion\Loan;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;

/**
 * Prêts de la gestion (lot FIS-1, 19/11/2026, contrat validé par le chef, `docs/prets-gestion.md`) : la fiche est stockée (`gestion_loan`),
 * l'échéancier est CALCULÉ (LoanScheduleCalculator), jamais stocké. Ce service sert l'écran (liste, fiche, aperçu sans écriture,
 * remboursements anticipés, exercice) et les autres calculs de la gestion :
 * - trésorerie : `cashFlows` (déblocage en entrée ; échéances totales, frais et remboursements anticipés en sortie) ;
 * - résultat, année, statistiques : `chargesBetween` (intérêts, assurance et frais AU PRORATA ; le capital n'est jamais une charge).
 * Montants en centimes ; taux en millionièmes ; dates sans heure (jour du magasin).
 */
class GestionLoanService
{
    public const FREQUENCIES = [1, 3];
    public const TYPES = [LoanScheduleCalculator::TYPE_ANNUITY, LoanScheduleCalculator::TYPE_CONSTANT];
    public const DEFERRAL_KINDS = [LoanScheduleCalculator::DEFERRAL_PARTIAL, LoanScheduleCalculator::DEFERRAL_TOTAL];
    public const INSURANCE_MODES = [LoanScheduleCalculator::INSURANCE_FIXED, LoanScheduleCalculator::INSURANCE_INITIAL, LoanScheduleCalculator::INSURANCE_REMAINING];
    public const MAX_AMOUNT = 100_000_000_000; // 1 milliard, en centimes
    public const MAX_RATE_PPM = 300_000; // 30 %
    public const MAX_DURATION = 600; // 50 ans
    public const MAX_PREPAYMENTS = 24;
    /** Montant le plus grand d'une colonne entière (frais, assurance par échéance) */
    public const MAX_INT_CENTS = 2_147_483_647;
    /** Ressource du journal des modifications (création, modification, suppression d'une fiche) */
    public const AUDIT = 'gestion-prets';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ?TenantCurrencyProvider $currency = null, private readonly ?ContentAuditRecorder $audit = null,
        private readonly ?\App\Gestion\Services\PeriodCalendar $calendar = null)
    {
    }

    /** @return list<array<string, mixed>> les prêts, du plus récent au plus ancien, avec leur résumé du jour */
    public function list(?string $today = null): array
    {
        $today ??= $this->today();

        return array_map(fn (array $r) => $this->summary($this->loan($r), $today), $this->conn()->fetchAllAssociative('SELECT * FROM gestion_loan ORDER BY disbursed_on DESC, id DESC'));
    }

    /** @return array<string, mixed> la fiche et son échéancier */
    public function get(int $id, ?string $today = null): array
    {
        $loan = $this->loan($this->row($id));
        $schedule = LoanScheduleCalculator::schedule($loan);

        return $this->summary($loan, $today ?? $this->today(), $schedule) + ['schedule' => $schedule['instalments']];
    }

    /** @return array<string, mixed>|null la fiche telle qu'elle est en base (sans calcul), ou null : sert aux actions du fil pour constater un changement */
    public function find(int $id): ?array
    {
        $r = $this->conn()->fetchAssociative('SELECT * FROM gestion_loan WHERE id = ?', [$id]);

        return $r === false ? null : $this->publicLoan($this->loan($r));
    }

    /**
     * L'échéancier SANS écriture, pendant la saisie.
     *
     * @return array{loan: array<string, mixed>, schedule: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function preview(array $body): array
    {
        $loan = $this->validate($body, null);
        $schedule = LoanScheduleCalculator::schedule($loan);

        return ['loan' => $this->publicLoan($loan), 'schedule' => $schedule['instalments'], 'totals' => $schedule['totals']];
    }

    /** @return array<string, mixed> */
    public function create(array $body, ?string $by): array
    {
        $loan = $this->validate($body, null);
        $now = gmdate('Y-m-d H:i:s');
        $id = (int) $this->conn()->fetchOne('INSERT INTO gestion_loan (label, lender, amount_cents, currency, rate_ppm, duration_months, frequency_months, disbursed_on, first_due_on, type, deferral_months,
            deferral_kind, insurance_mode, insurance_amount_cents, insurance_rate_ppm, fees_cents, prepayments, notes, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id', [...$this->columns($loan), $by !== null ? mb_substr($by, 0, 180) : null, $now, $now]);
        $this->audit?->record(self::AUDIT, $id, 'create', null, ['id' => $id] + $this->publicLoan($loan));

        return $this->get($id);
    }

    /** @return array<string, mixed> */
    public function update(int $id, array $body): array
    {
        $current = $this->loan($this->row($id));
        $loan = $this->validate($body + $this->publicLoan($current), $id);
        $this->conn()->executeStatement('UPDATE gestion_loan SET label = ?, lender = ?, amount_cents = ?, currency = ?, rate_ppm = ?, duration_months = ?, frequency_months = ?, disbursed_on = ?,
            first_due_on = ?, type = ?, deferral_months = ?, deferral_kind = ?, insurance_mode = ?, insurance_amount_cents = ?, insurance_rate_ppm = ?, fees_cents = ?, prepayments = ?, notes = ?, updated_at = ?
            WHERE id = ?', [...$this->columns($loan), gmdate('Y-m-d H:i:s'), $id]);
        $this->audit?->record(self::AUDIT, $id, 'update', $this->publicLoan($current), $this->publicLoan($loan));

        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $before = $this->publicLoan($this->loan($this->row($id)));
        $this->conn()->executeStatement('DELETE FROM gestion_loan WHERE id = ?', [$id]);
        $this->audit?->record(self::AUDIT, $id, 'delete', $before, null);
    }

    /** @return array<string, mixed> la fiche, avec un remboursement anticipé de plus */
    public function addPrepayment(int $id, array $body): array
    {
        $loan = $this->loan($this->row($id));
        $loan['prepayments'][] = ['date' => (string) ($body['date'] ?? ''), 'amount' => (int) ($body['amount'] ?? 0), 'fees' => (int) ($body['fees'] ?? 0)];

        return $this->update($id, ['prepayments' => $loan['prepayments']]);
    }

    /** @return array<string, mixed> */
    public function removePrepayment(int $id, int $index): array
    {
        $loan = $this->loan($this->row($id));
        if (!isset($loan['prepayments'][$index])) {
            throw new GestionConflictException('LOAN_PREPAYMENT_NOT_FOUND', 'Remboursement anticipé introuvable.', 404, ['index' => $index]);
        }
        array_splice($loan['prepayments'], $index, 1);

        return $this->update($id, ['prepayments' => $loan['prepayments']]);
    }

    /**
     * Pour un exercice (dossier de fin d'exercice à venir) : par prêt, capital au début et à la fin, capital remboursé, intérêts, assurance et
     * frais de l'exercice (au prorata).
     *
     * @return list<array<string, mixed>>
     */
    public function financialYear(string $from, string $to): array
    {
        $out = [];
        foreach ($this->conn()->fetchAllAssociative('SELECT * FROM gestion_loan ORDER BY id') as $r) {
            $loan = $this->loan($r);
            $out[] = ['loanId' => $loan['id'], 'label' => $loan['label'], 'lender' => $loan['lender'], 'currency' => $loan['currency']]
                + LoanScheduleCalculator::financialYear($loan, LoanScheduleCalculator::schedule($loan), $from, $to);
        }

        return $out;
    }

    /**
     * Flux de trésorerie de tous les prêts entre deux jours (bornes incluses).
     *
     * @return list<array{date: string, direction: string, amount: int, kind: string, loanId: int}>
     */
    public function cashFlows(string $from, string $to): array
    {
        $out = [];
        foreach ($this->loans() as $loan) {
            foreach (LoanScheduleCalculator::cashFlows($loan, LoanScheduleCalculator::schedule($loan), $from, $to) as $f) {
                $out[] = $f + ['loanId' => $loan['id']];
            }
        }

        return $out;
    }

    /**
     * Charges financières de tous les prêts sur une plage, au prorata (le capital n'est jamais une charge).
     *
     * @return array{interest: int, insurance: int, fees: int, total: int, loans: int}
     */
    public function chargesBetween(string $from, string $to): array
    {
        $sum = ['interest' => 0, 'insurance' => 0, 'fees' => 0, 'total' => 0, 'loans' => 0];
        foreach ($this->loans() as $loan) {
            $c = LoanScheduleCalculator::chargesBetween($loan, LoanScheduleCalculator::schedule($loan), $from, $to);
            if ($c['total'] > 0) {
                ++$sum['loans'];
            }
            foreach (['interest', 'insurance', 'fees', 'total'] as $k) {
                $sum[$k] += $c[$k];
            }
        }

        return $sum;
    }

    /** Capital remboursé entre deux jours (échéances et remboursements anticipés), prêts dans la devise du site : de la trésorerie, jamais une charge */
    public function capitalRepaidBetween(string $from, string $to): int
    {
        $sum = 0;
        foreach ($this->loans() as $loan) {
            $sum += (int) LoanScheduleCalculator::financialYear($loan, LoanScheduleCalculator::schedule($loan), $from, $to)['capitalRepaid'];
        }

        return $sum;
    }

    /** Un prêt dans la devise du site existe-t-il (de ceux qui entrent dans la trésorerie et le résultat) ? */
    public function hasLoans(): bool
    {
        return $this->conn()->fetchOne('SELECT 1 FROM gestion_loan WHERE currency = ? LIMIT 1', [$this->currency?->code() ?? TenantCurrencyProvider::DEFAULT]) !== false;
    }

    /** Un mouvement prévu de catégorie « prêt » saisi à la main existe-t-il à côté d'un prêt ? (remarque de double comptage, rien n'est supprimé) */
    public function mayOverlapMovements(): bool
    {
        return $this->hasLoans() && $this->conn()->fetchOne("SELECT 1 FROM gestion_cash_movement WHERE category = 'loan' LIMIT 1") !== false;
    }

    /**
     * Prêts qui entrent dans la trésorerie et le résultat : ceux dans la devise du site. Un prêt dans une autre devise reste dans la liste et
     * sa fiche, mais n'est jamais additionné à des montants dans la devise du site (aucune conversion : simplification dite dans la fiche).
     *
     * @return list<array<string, mixed>>
     */
    private function loans(): array
    {
        $site = $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT;

        return array_map(fn (array $r) => $this->loan($r), $this->conn()->fetchAllAssociative('SELECT * FROM gestion_loan WHERE currency = ? ORDER BY id', [$site]));
    }

    /**
     * Résumé d'un prêt pour la liste et la fiche : capital restant aujourd'hui, prochaine échéance, coût total du crédit (intérêts + assurance +
     * frais), et le capital remboursé à part des charges.
     *
     * @return array<string, mixed>
     */
    private function summary(array $loan, string $today, ?array $schedule = null): array
    {
        $schedule ??= LoanScheduleCalculator::schedule($loan);
        $next = null;
        foreach ($schedule['instalments'] as $row) {
            if ($row['date'] >= $today && $row['total'] > 0) {
                $next = $row;
                break;
            }
        }

        return $this->publicLoan($loan) + [
            'remainingToday' => LoanScheduleCalculator::capitalAt($loan, $schedule, $today),
            'nextInstalment' => $next,
            'lastDueOn' => $schedule['instalments'] !== [] ? end($schedule['instalments'])['date'] : null,
            'totals' => $schedule['totals'],
            'capitalized' => $schedule['capitalized'],
        ];
    }

    /** @return array<string, mixed> la fiche telle que les applications la lisent */
    private function publicLoan(array $loan): array
    {
        return array_intersect_key($loan, array_flip(['id', 'label', 'lender', 'amount', 'currency', 'ratePpm', 'durationMonths', 'frequencyMonths', 'disbursedOn', 'firstDueOn', 'type',
            'deferralMonths', 'deferralKind', 'insuranceMode', 'insuranceAmount', 'insuranceRatePpm', 'fees', 'prepayments', 'notes']));
    }

    /**
     * Vérifie une fiche complète ; un refus nomme chaque champ avec un code stable (422 VALIDATION_FAILED).
     *
     * @return array<string, mixed>
     * @throws GestionValidationException
     */
    private function validate(array $b, ?int $id): array
    {
        $errors = [];
        $date = static fn ($v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && \DateTimeImmutable::createFromFormat('!Y-m-d', $v)?->format('Y-m-d') === $v ? $v : null;
        $int = static fn ($v): ?int => is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', $v)) ? (int) $v : null;
        $label = trim(strip_tags((string) ($b['label'] ?? '')));
        if ($label === '' || mb_strlen($label) > 120) {
            $errors['label'] = 'LOAN_LABEL_INVALID';
        }
        $amount = $int($b['amount'] ?? null);
        if ($amount === null || $amount < 1 || $amount > self::MAX_AMOUNT) {
            $errors['amount'] = 'LOAN_AMOUNT_INVALID';
        }
        $rate = $int($b['ratePpm'] ?? null);
        if ($rate === null || $rate < 0 || $rate > self::MAX_RATE_PPM) {
            $errors['ratePpm'] = 'LOAN_RATE_INVALID';
        }
        $freq = $int($b['frequencyMonths'] ?? 1);
        if (!in_array($freq, self::FREQUENCIES, true)) {
            $errors['frequencyMonths'] = 'LOAN_FREQUENCY_INVALID';
            $freq = 1;
        }
        $duration = $int($b['durationMonths'] ?? null);
        if ($duration === null || $duration < $freq || $duration > self::MAX_DURATION || $duration % $freq !== 0) {
            $errors['durationMonths'] = 'LOAN_DURATION_INVALID';
        }
        $disbursed = $date($b['disbursedOn'] ?? null);
        $firstDue = $date($b['firstDueOn'] ?? null);
        if ($disbursed === null) {
            $errors['disbursedOn'] = 'LOAN_DATE_INVALID';
        }
        // la première échéance tombe APRÈS le déblocage, au plus deux rythmes plus tard (ses intérêts valent un rythme : ÷12 ou ÷4) ;
        // un écart plus grand se saisit comme un différé
        if ($firstDue === null || ($disbursed !== null && ($firstDue <= $disbursed || $firstDue > LoanScheduleCalculator::addMonths($disbursed, 2 * $freq)))) {
            $errors['firstDueOn'] = 'LOAN_DATE_INVALID';
        }
        $type = (string) ($b['type'] ?? LoanScheduleCalculator::TYPE_ANNUITY);
        if (!in_array($type, self::TYPES, true)) {
            $errors['type'] = 'LOAN_TYPE_INVALID';
        }
        $deferral = $int($b['deferralMonths'] ?? 0) ?? -1;
        $deferralKind = $b['deferralKind'] ?? null;
        if ($deferral < 0 || ($duration !== null && $deferral >= $duration) || $deferral % $freq !== 0 || ($deferral > 0 && !in_array($deferralKind, self::DEFERRAL_KINDS, true))) {
            $errors['deferralMonths'] = 'LOAN_DEFERRAL_INVALID';
        }
        $insuranceMode = $b['insuranceMode'] ?? null;
        $insuranceAmount = $int($b['insuranceAmount'] ?? 0) ?? -1;
        $insuranceRate = $int($b['insuranceRatePpm'] ?? 0) ?? -1;
        if (($insuranceMode !== null && !in_array($insuranceMode, self::INSURANCE_MODES, true)) || $insuranceAmount < 0 || $insuranceAmount > self::MAX_INT_CENTS || $insuranceRate < 0 || $insuranceRate > self::MAX_RATE_PPM
            || ($insuranceMode === LoanScheduleCalculator::INSURANCE_FIXED && $insuranceAmount === 0) || (in_array($insuranceMode, [LoanScheduleCalculator::INSURANCE_INITIAL, LoanScheduleCalculator::INSURANCE_REMAINING], true) && $insuranceRate === 0)) {
            $errors['insuranceMode'] = 'LOAN_INSURANCE_INVALID';
        }
        $fees = $int($b['fees'] ?? 0) ?? -1;
        if ($fees < 0 || $fees > self::MAX_INT_CENTS) {
            $errors['fees'] = 'LOAN_FEES_INVALID';
        }
        $currency = strtoupper(trim((string) ($b['currency'] ?? ($this->currency?->code() ?? TenantCurrencyProvider::DEFAULT))));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors['currency'] = 'LOAN_CURRENCY_INVALID';
        }
        $prepayments = [];
        $raw = $b['prepayments'] ?? [];
        if (!is_array($raw) || count($raw) > self::MAX_PREPAYMENTS) {
            $errors['prepayments'] = 'LOAN_PREPAYMENT_INVALID';
        } else {
            foreach (array_values($raw) as $p) {
                $pd = $date($p['date'] ?? null);
                $pa = $int($p['amount'] ?? null);
                $pf = $int($p['fees'] ?? 0) ?? -1;
                if ($pd === null || $pa === null || $pa < 1 || $pf < 0 || $pf > self::MAX_INT_CENTS || ($disbursed !== null && $pd <= $disbursed) || ($amount !== null && $pa > $amount)) {
                    $errors['prepayments'] = 'LOAN_PREPAYMENT_INVALID';
                    break;
                }
                $prepayments[] = ['date' => $pd, 'amount' => $pa, 'fees' => $pf];
            }
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Prêt invalide');
        }
        $lender = trim(strip_tags((string) ($b['lender'] ?? '')));
        $notes = trim(strip_tags((string) ($b['notes'] ?? '')));
        usort($prepayments, fn ($x, $y) => strcmp($x['date'], $y['date']));

        $loan = ['id' => $id, 'label' => $label, 'lender' => $lender !== '' ? mb_substr($lender, 0, 120) : null, 'amount' => $amount, 'currency' => $currency, 'ratePpm' => $rate,
            'durationMonths' => $duration, 'frequencyMonths' => $freq, 'disbursedOn' => $disbursed, 'firstDueOn' => $firstDue, 'type' => $type, 'deferralMonths' => $deferral,
            'deferralKind' => $deferral > 0 ? $deferralKind : null, 'insuranceMode' => $insuranceMode, 'insuranceAmount' => $insuranceMode === LoanScheduleCalculator::INSURANCE_FIXED ? $insuranceAmount : 0,
            'insuranceRatePpm' => in_array($insuranceMode, [LoanScheduleCalculator::INSURANCE_INITIAL, LoanScheduleCalculator::INSURANCE_REMAINING], true) ? $insuranceRate : 0,
            'fees' => $fees, 'prepayments' => $prepayments, 'notes' => $notes !== '' ? mb_substr($notes, 0, 1000) : null];
        // un remboursement anticipé s'applique EN ENTIER : avant la dernière échéance, et pas plus que le capital restant dû à sa date
        // (sinon la trésorerie sortirait un montant que l'échéancier n'applique pas)
        if ($prepayments !== []) {
            foreach (LoanScheduleCalculator::schedule($loan)['prepayments'] as $applied) {
                if ($applied['amount'] < $applied['requested']) {
                    throw new GestionValidationException(['prepayments' => 'LOAN_PREPAYMENT_INVALID'], 'Prêt invalide');
                }
            }
        }

        return $loan;
    }

    /** @return list<mixed> les colonnes d'écriture, dans l'ordre des requêtes */
    private function columns(array $l): array
    {
        return [$l['label'], $l['lender'], $l['amount'], $l['currency'], $l['ratePpm'], $l['durationMonths'], $l['frequencyMonths'], $l['disbursedOn'], $l['firstDueOn'], $l['type'],
            $l['deferralMonths'], $l['deferralKind'], $l['insuranceMode'], $l['insuranceAmount'], $l['insuranceRatePpm'], $l['fees'], $l['prepayments'] !== [] ? json_encode($l['prepayments']) : null, $l['notes']];
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $r = $this->conn()->fetchAssociative('SELECT * FROM gestion_loan WHERE id = ?', [$id]);
        if ($r === false) {
            throw new GestionConflictException('LOAN_NOT_FOUND', 'Prêt introuvable.', 404, ['loanId' => $id]);
        }

        return $r;
    }

    /** @return array<string, mixed> la fiche au format du calcul */
    private function loan(array $r): array
    {
        return ['id' => (int) $r['id'], 'label' => (string) $r['label'], 'lender' => $r['lender'], 'amount' => (int) $r['amount_cents'], 'currency' => (string) $r['currency'],
            'ratePpm' => (int) $r['rate_ppm'], 'durationMonths' => (int) $r['duration_months'], 'frequencyMonths' => (int) $r['frequency_months'], 'disbursedOn' => (string) $r['disbursed_on'],
            'firstDueOn' => (string) $r['first_due_on'], 'type' => (string) $r['type'], 'deferralMonths' => (int) $r['deferral_months'], 'deferralKind' => $r['deferral_kind'],
            'insuranceMode' => $r['insurance_mode'], 'insuranceAmount' => (int) $r['insurance_amount_cents'], 'insuranceRatePpm' => (int) $r['insurance_rate_ppm'], 'fees' => (int) $r['fees_cents'],
            'prepayments' => is_string($r['prepayments']) ? (json_decode($r['prepayments'], true) ?: []) : [], 'notes' => $r['notes']];
    }

    /** Le jour du magasin (fuseau du site), comme l'écran de la trésorerie et la console : jamais le jour UTC */
    private function today(): string
    {
        return $this->calendar?->today()->format('Y-m-d') ?? gmdate('Y-m-d');
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
