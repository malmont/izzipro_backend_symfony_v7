<?php

namespace App\Gestion\Aid;

/**
 * Calcul PUR des aides de la gestion (lot FIS-2, 21/11/2026, `docs/aides-gestion.md`) : aucune base, aucune horloge. Une aide entre au
 * format de GestionAidService::aid() ; montants en centimes ; dates `AAAA-MM-JJ` (jour du magasin).
 *
 * - Trésorerie : les versements d'une aide d'exploitation ou d'investissement (reçus ; prévus seulement si l'aide est accordée), et le
 *   remboursement décidé d'une aide qui n'est pas une avance. Une avance remboursable n'a aucun flux ici : son prêt à 0 % (lot FIS-1) porte
 *   le déblocage et les remboursements, pour ne jamais compter l'argent deux fois.
 * - Résultat : seulement l'aide d'EXPLOITATION accordée, au prorata de la période couverte (cumul arrondi : additif au cent), sinon à la
 *   date de chaque versement. Elle se montre en deux parts, « reçu » et « accordé, pas encore reçu », dans la proportion de ce qui est déjà
 *   reçu (une estimation, dite par l'écran). Un remboursement décidé la fait baisser sur la période de la DÉCISION.
 */
final class AidCalculator
{
    public const KIND_OPERATING = 'operating';
    public const KIND_INVESTMENT = 'investment';
    public const KIND_ADVANCE = 'refundable_advance';
    public const KINDS = [self::KIND_OPERATING, self::KIND_INVESTMENT, self::KIND_ADVANCE];

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_GRANTED = 'granted';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_TO_REPAY = 'to_repay';
    public const STATUS_CLOSED = 'closed';
    public const STATUSES = [self::STATUS_REQUESTED, self::STATUS_GRANTED, self::STATUS_RECEIVED, self::STATUS_TO_REPAY, self::STATUS_CLOSED];
    /** États où l'aide est acquise : son produit compte, ses versements prévus entrent dans la trésorerie */
    public const ENGAGED = [self::STATUS_GRANTED, self::STATUS_RECEIVED, self::STATUS_TO_REPAY, self::STATUS_CLOSED];

    /**
     * Flux de trésorerie d'une aide entre deux jours (bornes incluses).
     *
     * @return list<array{date: string, direction: string, amount: int, kind: string}>
     */
    public static function cashFlows(array $aid, string $from, string $to): array
    {
        if ($aid['kind'] === self::KIND_ADVANCE) {
            return [];
        }
        $out = [];
        foreach (self::countedPayments($aid) as $p) {
            if ($p['date'] >= $from && $p['date'] <= $to) {
                $out[] = ['date' => $p['date'], 'direction' => 'in', 'amount' => $p['amount'], 'kind' => 'payment'];
            }
        }
        $r = self::repayment($aid);
        if ($r !== null && $r['dueOn'] >= $from && $r['dueOn'] <= $to) {
            $out[] = ['date' => $r['dueOn'], 'direction' => 'out', 'amount' => $r['amount'], 'kind' => 'repayment'];
        }

        return $out;
    }

    /**
     * Les versements qui comptent : un versement reçu compte toujours (l'argent est là) ; un versement prévu, seulement si l'aide est
     * accordée et pas encore close (une aide demandée n'est pas une rentrée sûre ; une aide close n'attend plus rien).
     *
     * @return list<array{date: string, amount: int, received: bool}>
     */
    public static function countedPayments(array $aid): array
    {
        $planned = in_array($aid['status'], [self::STATUS_GRANTED, self::STATUS_RECEIVED, self::STATUS_TO_REPAY], true);

        return array_values(array_filter($aid['payments'], static fn (array $p): bool => $p['received'] || $planned));
    }

    /** Le remboursement décidé, quand l'aide (pas une avance) est à rembourser ou close après remboursement ; sinon null */
    public static function repayment(array $aid): ?array
    {
        if ($aid['kind'] === self::KIND_ADVANCE || $aid['repayment'] === null || !in_array($aid['status'], [self::STATUS_TO_REPAY, self::STATUS_CLOSED], true)) {
            return null;
        }

        return $aid['repayment'];
    }

    /**
     * Le produit d'une aide d'exploitation sur une plage : `received` + `pending` − `repaid` = `total`.
     *
     * @return array{received: int, pending: int, repaid: int, total: int}
     */
    public static function productBetween(array $aid, string $from, string $to): array
    {
        $zero = ['received' => 0, 'pending' => 0, 'repaid' => 0, 'total' => 0];
        if ($aid['kind'] !== self::KIND_OPERATING || !in_array($aid['status'], self::ENGAGED, true) || $from > $to) {
            return $zero;
        }
        $granted = (int) $aid['amount'];
        if ($aid['coveredFrom'] !== null && $aid['coveredTo'] !== null) {
            $product = self::cumulative($granted, $aid['coveredFrom'], $aid['coveredTo'], $to) - self::cumulative($granted, $aid['coveredFrom'], $aid['coveredTo'], self::addDays($from, -1));
            // la part reçue, dans la proportion de ce qui est déjà reçu de l'aide entière (arrondi au plus proche ; produit positif)
            $receivedAll = min($granted, array_sum(array_map(static fn (array $p): int => $p['received'] ? $p['amount'] : 0, $aid['payments'])));
            $received = min($product, $granted > 0 ? intdiv($product * $receivedAll * 2 + $granted, 2 * $granted) : 0);
        } else {
            // sans période couverte : à la date de chaque versement accordé ; reçu ou prévu, versement par versement
            $product = $received = 0;
            foreach ($aid['payments'] as $p) {
                if ($p['date'] >= $from && $p['date'] <= $to) {
                    $product += $p['amount'];
                    $received += $p['received'] ? $p['amount'] : 0;
                }
            }
        }
        $repaid = 0;
        $r = self::repayment($aid);
        if ($r !== null && $r['decidedOn'] >= $from && $r['decidedOn'] <= $to) {
            $repaid = $r['amount'];
        }

        return ['received' => $received, 'pending' => $product - $received, 'repaid' => $repaid, 'total' => $product - $repaid];
    }

    /** Part du montant accordé courue du début de la période couverte jusqu'au jour `$day` inclus (0 avant, tout après) : cumul arrondi */
    private static function cumulative(int $granted, string $coveredFrom, string $coveredTo, string $day): int
    {
        if ($day < $coveredFrom) {
            return 0;
        }
        $total = self::days($coveredFrom, $coveredTo);
        $elapsed = self::days($coveredFrom, min($day, $coveredTo));

        return $total > 0 ? intdiv($granted * $elapsed * 2 + $total, 2 * $total) : $granted;
    }

    /** Nombre de jours de `$from` à `$to`, bornes incluses */
    public static function days(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
    }

    public static function addDays(string $day, int $n): string
    {
        return (new \DateTimeImmutable($day))->modify(sprintf('%+d days', $n))->format('Y-m-d');
    }

    /** Clé stable d'une condition pour les rappels (son libellé et son échéance : la changer, c'est une autre condition) */
    public static function conditionKey(array $condition): string
    {
        return 'c:' . substr(hash('sha256', $condition['label'] . '|' . $condition['dueOn']), 0, 16);
    }
}
