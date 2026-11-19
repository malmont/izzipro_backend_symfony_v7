<?php

namespace App\Gestion\Loan;

/**
 * Échéancier d'un prêt (lot FIS-1, 19/11/2026, contrat validé par le chef, `docs/plan-impots.md` § 3.1) : calcul PUR, jamais stocké.
 *
 * Conventions (décisions du chef) :
 * - intérêts par période : taux annuel ÷ 12 (mensuel) ou ÷ 4 (trimestriel), comme les banques pour un prêt amortissable ;
 * - montants en centimes, arrondis au cent à chaque échéance (demi vers le haut) ; la DERNIÈRE échéance porte l'écart : la somme des
 *   capitaux remboursés égale exactement le montant emprunté (plus les intérêts capitalisés d'un différé total) ;
 * - la durée comprend le différé ; différé `partial` : intérêts seuls ; `total` : rien n'est payé, les intérêts s'ajoutent au capital ;
 * - assurance : montant fixe par échéance, ou taux annuel sur le capital initial ou sur le capital restant dû en début de période ;
 * - frais de dossier : une charge à la date de déblocage ;
 * - remboursement anticipé : il réduit le capital juste après la dernière échéance qui le précède (ou au déblocage) ; les échéances suivantes
 *   sont recalculées sur la durée restante (la date de fin ne change pas) ; ses frais éventuels sont une charge à sa date.
 */
final class LoanScheduleCalculator
{
    public const TYPE_ANNUITY = 'annuity';
    public const TYPE_CONSTANT = 'constant_capital';
    public const DEFERRAL_PARTIAL = 'partial';
    public const DEFERRAL_TOTAL = 'total';
    public const INSURANCE_FIXED = 'fixed';
    public const INSURANCE_INITIAL = 'initial';
    public const INSURANCE_REMAINING = 'remaining';

    /**
     * @param array{amount: int, ratePpm: int, durationMonths: int, frequencyMonths: int, disbursedOn: string, firstDueOn: string, type: string,
     *               deferralMonths?: int, deferralKind?: ?string, insuranceMode?: ?string, insuranceAmount?: int, insuranceRatePpm?: int, fees?: int,
     *               prepayments?: list<array{date: string, amount: int, fees?: int}>} $loan
     * @return array{instalments: list<array{n: int, date: string, periodStart: string, capital: int, interest: int, insurance: int, total: int, remaining: int, deferral: bool, prepayment: int}>,
     *               totals: array{capital: int, interest: int, insurance: int, fees: int, prepaymentFees: int, creditCost: int, paid: int}, capitalized: int}
     */
    public static function schedule(array $loan): array
    {
        $f = (int) $loan['frequencyMonths'];
        $n = intdiv((int) $loan['durationMonths'], $f);
        $d = intdiv((int) ($loan['deferralMonths'] ?? 0), $f);
        $rate = ((int) $loan['ratePpm']) / 1_000_000 * $f / 12;
        $remaining = (int) $loan['amount'];
        $initial = $remaining;
        $prepayments = $loan['prepayments'] ?? [];
        usort($prepayments, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $applied = array_fill(0, count($prepayments), false);
        $rows = [];
        $capitalized = 0;
        $payment = null; // échéance constante (hors assurance), recalculée après un remboursement anticipé
        $previous = (string) $loan['disbursedOn'];
        for ($k = 1; $k <= $n; ++$k) {
            $date = self::addMonths((string) $loan['firstDueOn'], ($k - 1) * $f);
            // les remboursements anticipés datés AVANT cette échéance réduisent le capital avant son calcul
            $prepaid = 0;
            foreach ($prepayments as $i => $p) {
                if ($applied[$i] === false && $p['date'] < $date) {
                    $cut = min($remaining, (int) $p['amount']);
                    $remaining -= $cut;
                    $prepaid += $cut;
                    $applied[$i] = $cut;
                    $payment = null;
                }
            }
            $insurance = match ($loan['insuranceMode'] ?? null) {
                self::INSURANCE_FIXED => (int) ($loan['insuranceAmount'] ?? 0),
                self::INSURANCE_INITIAL => self::round($initial * ((int) ($loan['insuranceRatePpm'] ?? 0)) / 1_000_000 * $f / 12),
                self::INSURANCE_REMAINING => self::round($remaining * ((int) ($loan['insuranceRatePpm'] ?? 0)) / 1_000_000 * $f / 12),
                default => 0,
            };
            if ($remaining <= 0) {
                $rows[] = ['n' => $k, 'date' => $date, 'periodStart' => $previous, 'capital' => 0, 'interest' => 0, 'insurance' => 0, 'total' => 0, 'remaining' => 0, 'deferral' => false, 'prepayment' => $prepaid];
                $previous = $date;
                continue;
            }
            $interest = self::round($remaining * $rate);
            if ($k <= $d) {
                // différé : intérêts seuls (partial) ou capitalisés (total)
                if (($loan['deferralKind'] ?? self::DEFERRAL_PARTIAL) === self::DEFERRAL_TOTAL) {
                    $remaining += $interest;
                    $capitalized += $interest;
                    // les intérêts capitalisés ne sont pas payés (total = assurance seule) mais ils COURENT : ils sont une charge de la période
                    // et une part du coût du crédit ; ils s'ajoutent au capital restant dû, remboursé ensuite avec lui
                    $rows[] = ['n' => $k, 'date' => $date, 'periodStart' => $previous, 'capital' => 0, 'interest' => $interest, 'insurance' => $insurance, 'total' => $insurance, 'remaining' => $remaining, 'deferral' => true, 'prepayment' => $prepaid];
                } else {
                    $rows[] = ['n' => $k, 'date' => $date, 'periodStart' => $previous, 'capital' => 0, 'interest' => $interest, 'insurance' => $insurance, 'total' => $interest + $insurance, 'remaining' => $remaining, 'deferral' => true, 'prepayment' => $prepaid];
                }
                $previous = $date;
                continue;
            }
            $left = $n - $k + 1; // échéances d'amortissement restantes, celle-ci comprise
            if ($k === $n) {
                $capital = $remaining; // la dernière échéance porte l'écart d'arrondi
            } elseif ($loan['type'] === self::TYPE_CONSTANT) {
                $capital = min($remaining, self::round($remaining / $left));
            } else {
                $payment ??= $rate > 0 ? self::round($remaining * $rate / (1 - (1 + $rate) ** (-$left))) : self::round($remaining / $left);
                $capital = min($remaining, max(0, $payment - $interest));
            }
            if ($loan['type'] === self::TYPE_CONSTANT) {
                $payment = null; // capital constant : recalculé à chaque échéance sur ce qui reste
            }
            $remaining -= $capital;
            $rows[] = ['n' => $k, 'date' => $date, 'periodStart' => $previous, 'capital' => $capital, 'interest' => $interest, 'insurance' => $insurance, 'total' => $capital + $interest + $insurance, 'remaining' => $remaining, 'deferral' => false, 'prepayment' => $prepaid];
            $previous = $date;
        }
        $fees = (int) ($loan['fees'] ?? 0);
        $prepaymentFees = array_sum(array_map(fn ($p) => (int) ($p['fees'] ?? 0), $prepayments));
        // capital : le montant emprunté remboursé (échéances et remboursements anticipés), SANS les intérêts capitalisés d'un différé total,
        // déjà comptés dans les intérêts : capital + coût du crédit = tout ce qui sort pour le prêt (`paid`)
        $totals = ['capital' => array_sum(array_column($rows, 'capital')) + array_sum(array_column($rows, 'prepayment')) - $capitalized, 'interest' => array_sum(array_column($rows, 'interest')),
            'insurance' => array_sum(array_column($rows, 'insurance')), 'fees' => $fees, 'prepaymentFees' => $prepaymentFees];
        // coût total du crédit : intérêts + assurance + frais ; le capital remboursé n'en fait jamais partie
        $totals['creditCost'] = $totals['interest'] + $totals['insurance'] + $fees + $prepaymentFees;
        $totals['paid'] = $totals['capital'] + $totals['creditCost'];

        // remboursements anticipés tels qu'appliqués : montant réellement retiré du capital (0 s'il tombe après la dernière échéance)
        $applied = array_map(fn (array $p, int|false $cut) => ['date' => $p['date'], 'amount' => $cut === false ? 0 : $cut, 'requested' => (int) $p['amount'], 'fees' => (int) ($p['fees'] ?? 0)],
            $prepayments, $applied);

        return ['instalments' => $rows, 'totals' => $totals, 'capitalized' => $capitalized, 'prepayments' => $applied];
    }

    /**
     * Charges du prêt sur une plage, AU PRORATA (décision Q3) : les intérêts et l'assurance de chaque échéance sont répartis sur les jours de sa
     * période ; frais de dossier et frais de remboursement anticipé à leur date. Le capital n'est jamais une charge.
     *
     * @param array<string, mixed> $loan
     * @return array{interest: int, insurance: int, fees: int, total: int}
     */
    public static function chargesBetween(array $loan, array $schedule, string $from, string $to): array
    {
        // Cumul arrondi : les charges d'une plage = cumul couru à sa fin − cumul couru à son début, chacun arrondi au cent. Des plages
        // consécutives s'additionnent donc EXACTEMENT (aucun cent perdu ni gagné entre deux périodes), et toutes les plages réunies
        // redonnent les intérêts et l'assurance de l'échéancier.
        $fromTs = strtotime($from . ' 00:00:00 UTC');
        $toTs = strtotime($to . ' 00:00:00 UTC') + 86400; // borne de fin incluse
        $i = self::round(self::accrued($schedule, $toTs, 'interest')) - self::round(self::accrued($schedule, $fromTs, 'interest'));
        $s = self::round(self::accrued($schedule, $toTs, 'insurance')) - self::round(self::accrued($schedule, $fromTs, 'insurance'));
        $fees = 0;
        if ($loan['disbursedOn'] >= $from && $loan['disbursedOn'] <= $to) {
            $fees += (int) ($loan['fees'] ?? 0);
        }
        foreach ($loan['prepayments'] ?? [] as $p) {
            if ($p['date'] >= $from && $p['date'] <= $to) {
                $fees += (int) ($p['fees'] ?? 0);
            }
        }

        return ['interest' => $i, 'insurance' => $s, 'fees' => $fees, 'total' => $i + $s + $fees];
    }

    /**
     * Part d'intérêts (ou d'assurance) courue avant un instant : chaque échéance court de son début (inclus) à sa date (exclue), au
     * prorata des secondes, donc des jours.
     */
    private static function accrued(array $schedule, int $until, string $field): float
    {
        $sum = 0.0;
        foreach ($schedule['instalments'] as $row) {
            $start = strtotime($row['periodStart'] . ' 00:00:00 UTC');
            $end = strtotime($row['date'] . ' 00:00:00 UTC');
            if ($until >= $end) {
                $sum += $row[$field];
            } elseif ($until > $start) {
                $sum += $row[$field] * ($until - $start) / max(1, $end - $start);
            }
        }

        return $sum;
    }

    /**
     * Les flux de trésorerie du prêt sur une plage : entrée du déblocage, sorties des échéances (total), des frais et des remboursements
     * anticipés, à leur date.
     *
     * @return list<array{date: string, direction: string, amount: int, kind: string}>
     */
    public static function cashFlows(array $loan, array $schedule, string $from, string $to): array
    {
        $out = [];
        $in = fn (string $d) => $d >= $from && $d <= $to;
        if ($in($loan['disbursedOn'])) {
            $out[] = ['date' => $loan['disbursedOn'], 'direction' => 'in', 'amount' => (int) $loan['amount'], 'kind' => 'disbursement'];
            if ((int) ($loan['fees'] ?? 0) > 0) {
                $out[] = ['date' => $loan['disbursedOn'], 'direction' => 'out', 'amount' => (int) $loan['fees'], 'kind' => 'fees'];
            }
        }
        foreach ($schedule['instalments'] as $row) {
            if ($row['total'] > 0 && $in($row['date'])) {
                $out[] = ['date' => $row['date'], 'direction' => 'out', 'amount' => $row['total'], 'kind' => 'instalment'];
            }
        }
        foreach ($schedule['prepayments'] ?? $loan['prepayments'] ?? [] as $p) {
            if ($in($p['date'])) {
                $out[] = ['date' => $p['date'], 'direction' => 'out', 'amount' => (int) $p['amount'] + (int) ($p['fees'] ?? 0), 'kind' => 'prepayment'];
            }
        }

        return $out;
    }

    /**
     * Pour un exercice (futur dossier de fin d'exercice, lot CLO) : capital restant au début et à la fin, intérêts, assurance et frais de
     * l'exercice (au prorata), capital remboursé dans l'exercice.
     *
     * @return array{capitalStart: int, capitalEnd: int, capitalRepaid: int, interest: int, insurance: int, fees: int}
     */
    public static function financialYear(array $loan, array $schedule, string $from, string $to): array
    {
        $before = fn (string $d) => self::capitalAt($loan, $schedule, $d);
        $start = $before((new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d'));
        $end = $before($to);
        $charges = self::chargesBetween($loan, $schedule, $from, $to);
        $repaid = 0;
        foreach ($schedule['instalments'] as $row) {
            if ($row['date'] >= $from && $row['date'] <= $to) {
                $repaid += $row['capital'];
            }
        }
        foreach ($schedule['prepayments'] ?? $loan['prepayments'] ?? [] as $p) {
            if ($p['date'] >= $from && $p['date'] <= $to) {
                $repaid += (int) $p['amount'];
            }
        }

        return ['capitalStart' => $start, 'capitalEnd' => $end, 'capitalRepaid' => $repaid, 'interest' => $charges['interest'], 'insurance' => $charges['insurance'], 'fees' => $charges['fees']];
    }

    /** Capital restant dû à la fin d'un jour (0 avant le déblocage) */
    public static function capitalAt(array $loan, array $schedule, string $day): int
    {
        if ($day < $loan['disbursedOn']) {
            return 0;
        }
        // l'état de la dernière échéance passée (son capital restant dû compte les remboursements anticipés datés AVANT elle), puis les
        // remboursements anticipés faits depuis, jusqu'à ce jour compris
        $remaining = (int) $loan['amount'];
        $since = '';
        foreach ($schedule['instalments'] as $row) {
            if ($row['date'] <= $day) {
                $remaining = $row['remaining'];
                $since = $row['date'];
            }
        }
        foreach ($schedule['prepayments'] ?? $loan['prepayments'] ?? [] as $p) {
            if ($p['date'] >= $since && $p['date'] <= $day) {
                $remaining -= (int) $p['amount'];
            }
        }

        return max(0, $remaining);
    }

    /** Ajoute des mois en gardant le jour, borné au dernier jour du mois (31 janvier + 1 mois = 28 ou 29 février) */
    public static function addMonths(string $date, int $months): string
    {
        $d = new \DateTimeImmutable($date . ' 00:00:00 UTC');
        $day = (int) $d->format('j');
        $first = $d->modify('first day of this month')->modify(sprintf('%+d months', $months));
        $last = (int) $first->format('t');

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($day, $last))->format('Y-m-d');
    }

    private static function round(float $v): int
    {
        return (int) round($v, 0, \PHP_ROUND_HALF_UP);
    }
}
