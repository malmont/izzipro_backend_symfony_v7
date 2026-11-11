<?php

namespace App\Gestion\Services;

use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;

/**
 * Rapprochement bancaire de la trésorerie (lot RAP, 11/11/2026, `docs/tresorerie-gestion.md` § 6, contrat validé par l'application).
 *
 * Pour un solde S du jour D et le solde précédent P (date strictement antérieure) : attendu = P + entrées prévues − sorties prévues − dépenses
 * saisies, datées dans ]P, D], + encaissements réels de [P, D − 1] (convention : un solde du jour X comprend les encaissements jusqu'à la veille,
 * les ventes par carte arrivant le lendemain ; chaque jour d'encaissement est ainsi compté une fois, ni oublié ni doublé). Les mêmes calculs que
 * la projection (`GestionCashService`), sans deuxième règle. L'attendu est recalculé à chaque lecture : une dépense oubliée saisie plus tard réduit
 * l'écart toute seule ; « Écart non justifié » est une décision posée sur le solde, jamais un montant figé ni un faux mouvement.
 */
class GestionReconciliationService
{
    public const DECISIONS = ['unexplained', 'explained', 'open'];
    public const SUGGESTIONS = ['read_receipt', 'add_expense', 'mark_unexplained'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly GestionCashService $cash,
        private readonly ContentAuditRecorder $audit,
        private readonly ?TenantCurrencyProvider $currency = null,
    ) {
    }

    /**
     * Rapprochement d'un solde (null pour le solde d'ouverture, sans solde précédent).
     *
     * @return array<string, mixed>|null
     */
    public function forBalance(int $balanceId, ?int $tolerance = null): ?array
    {
        $row = $this->conn()->fetchAssociative('SELECT * FROM gestion_cash_balance WHERE id = ?', [$balanceId]);
        if ($row === false) {
            throw new GestionConflictException('BALANCE_NOT_FOUND', 'Solde introuvable.', 404, ['balanceId' => $balanceId]);
        }
        $previous = $this->conn()->fetchAssociative('SELECT * FROM gestion_cash_balance WHERE balance_date < ? ORDER BY balance_date DESC LIMIT 1', [$row['balance_date']]);

        return $previous === false ? null : $this->item($row, $previous, $tolerance ?? $this->cash->settings()['tolerance']);
    }

    /** Après la saisie d'un solde : attendu gardé pour l'historique, rapprochement rendu */
    public function afterSave(int $balanceId): ?array
    {
        $rec = $this->forBalance($balanceId);
        $this->conn()->executeStatement('UPDATE gestion_cash_balance SET expected_at_entry = ? WHERE id = ?', [$rec['expected'] ?? null, $balanceId]);

        return $rec === null ? null : ['expectedAtEntry' => $rec['expected']] + $rec;
    }

    /** GET /api/gestion/tresorerie/rapprochements : du plus récent au plus ancien, soldes d'ouverture exclus */
    public function list(int $limit): array
    {
        $tolerance = $this->cash->settings()['tolerance'];
        $rows = $this->conn()->fetchAllAssociative('SELECT * FROM gestion_cash_balance ORDER BY balance_date DESC LIMIT ' . (max(1, min(200, $limit)) + 1));
        $items = [];
        for ($i = 0; $i < count($rows) - 1 && count($items) < $limit; $i++) {
            $items[] = $this->item($rows[$i], $rows[$i + 1], $tolerance);
        }

        return ['currency' => $this->siteCurrency(), 'tolerance' => $tolerance, 'items' => $items, 'unexplained' => $this->unexplained($tolerance)];
    }

    /** Résumé pour la trésorerie, la vignette et la clôture du mois : dernier rapprochement, écarts non justifiés */
    public function summary(): array
    {
        $tolerance = $this->cash->settings()['tolerance'];
        $rows = $this->conn()->fetchAllAssociative('SELECT * FROM gestion_cash_balance ORDER BY balance_date DESC LIMIT 2');
        $last = count($rows) === 2 ? $this->item($rows[0], $rows[1], $tolerance) : null;

        return ['lastStatus' => $last['status'] ?? null, 'lastGap' => $last['gap'] ?? null, 'lastDate' => $last['date'] ?? null, 'tolerance' => $tolerance, 'unexplained' => $this->unexplained($tolerance)];
    }

    /** POST /api/gestion/tresorerie/soldes/{id}/ecart { decision, note? } */
    public function decide(int $balanceId, array $data, ?string $by): array
    {
        $decision = $data['decision'] ?? null;
        $errors = [];
        if (!is_string($decision) || !in_array($decision, self::DECISIONS, true)) {
            $errors['decision'] = $decision === null ? 'REQUIRED' : 'INVALID_DECISION';
        }
        $note = $data['note'] ?? null;
        if ($note !== null && !is_string($note)) {
            $errors['note'] = 'MUST_BE_STRING';
        } elseif (is_string($note) && mb_strlen(trim($note)) > 500) {
            $errors['note'] = 'TOO_LONG';
        }
        $note = is_string($note) && trim($note) !== '' ? trim($note) : null;
        if ($decision === 'explained' && $note === null && !isset($errors['note'])) {
            $errors['note'] = 'REQUIRED';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Décision invalide : ' . implode(', ', array_keys($errors)));
        }
        $rec = $this->forBalance($balanceId);
        if ($rec === null) {
            throw new GestionConflictException('NO_PREVIOUS_BALANCE', 'Solde d\'ouverture : aucun solde précédent à rapprocher.', 409, ['balanceId' => $balanceId]);
        }
        if ($decision !== 'open' && $rec['status'] === 'matched') {
            throw new GestionConflictException('GAP_WITHIN_TOLERANCE', 'L\'écart est déjà dans la tolérance.', 409, ['reconciliation' => $rec]);
        }
        $before = $rec['decision'];
        if ($decision === 'open') {
            $this->conn()->executeStatement('UPDATE gestion_cash_balance SET gap_decision = NULL, gap_note = NULL, gap_decided_by = NULL, gap_decided_at = NULL WHERE id = ?', [$balanceId]);
        } else {
            $this->conn()->executeStatement('UPDATE gestion_cash_balance SET gap_decision = ?, gap_note = ?, gap_decided_by = ?, gap_decided_at = NOW() WHERE id = ?', [$decision, $note, $by, $balanceId]);
        }
        $after = $this->forBalance($balanceId);
        $this->audit->record('gestion-tresorerie-ecarts', $balanceId, 'update', ['decision' => $before, 'gap' => $rec['gap']], ['decision' => $after['decision'], 'gap' => $after['gap']]);

        return $after;
    }

    /** @return array<string, mixed> un rapprochement : solde courant `$row`, solde précédent `$previous` */
    private function item(array $row, array $previous, int $tolerance): array
    {
        $date = substr((string) $row['balance_date'], 0, 10);
        $prevDate = substr((string) $previous['balance_date'], 0, 10);
        $after = (new \DateTimeImmutable($prevDate))->modify('+1 day')->format('Y-m-d');
        $eve = (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
        $planned = $this->cash->plannedBetween($after, $date);
        $recorded = $after <= $date ? $this->cash->recordedExpenses($after, $date) : ['days' => [], 'details' => ['purchases' => 0, 'shipping' => 0, 'notes' => 0, 'advertising' => 0]];
        $receipts = $this->cash->receiptsSplit($prevDate, $eve);
        $recordedTotal = array_sum($recorded['days']);
        $expected = (int) $previous['amount_cents'] + $planned['in'] - $planned['out'] - $recordedTotal + $receipts['total'];
        $actual = (int) $row['amount_cents'];
        $gap = $actual - $expected;
        $decision = $row['gap_decision'] === null ? null : [
            'kind' => (string) $row['gap_decision'], 'note' => $row['gap_note'], 'by' => $row['gap_decided_by'],
            'at' => $row['gap_decided_at'] === null ? null : (new \DateTimeImmutable((string) $row['gap_decided_at']))->format(\DateTimeInterface::ATOM),
        ];
        $status = abs($gap) <= $tolerance ? 'matched' : ($decision['kind'] ?? 'open');

        return [
            'balanceId' => (int) $row['id'], 'date' => $date, 'previousDate' => $prevDate,
            'expected' => $expected, 'expectedAtEntry' => $row['expected_at_entry'] === null ? null : (int) $row['expected_at_entry'],
            'actual' => $actual, 'gap' => $gap, 'status' => $status, 'decision' => $decision,
            'suggestions' => $status === 'open' ? self::SUGGESTIONS : [],
            'expenseWindow' => ['from' => $after, 'to' => $date], // où dater une dépense oubliée pour qu'elle réduise cet écart
            'composition' => [
                'previous' => ['id' => (int) $previous['id'], 'date' => $prevDate, 'amount' => (int) $previous['amount_cents']],
                'plannedIn' => $planned['in'], 'plannedOut' => $planned['out'],
                'recordedExpenses' => ['total' => $recordedTotal] + $recorded['details'],
                'receipts' => $receipts + ['from' => $prevDate <= $eve ? $prevDate : null, 'to' => $prevDate <= $eve ? $eve : null],
                'expected' => $expected,
                'days' => (int) (new \DateTimeImmutable($prevDate))->diff(new \DateTimeImmutable($date))->format('%a'),
            ],
        ];
    }

    /** Écarts non justifiés : écart vivant des soldes marqués « non justifié » encore hors tolérance */
    private function unexplained(int $tolerance): array
    {
        $total = 0;
        $count = 0;
        foreach ($this->conn()->fetchFirstColumn("SELECT id FROM gestion_cash_balance WHERE gap_decision = 'unexplained' ORDER BY balance_date") as $id) {
            $rec = $this->forBalance((int) $id, $tolerance);
            if ($rec !== null && $rec['status'] === 'unexplained') {
                $total += $rec['gap'];
                ++$count;
            }
        }

        return ['total' => $total, 'count' => $count];
    }

    private function siteCurrency(): string
    {
        return $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT;
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
