<?php

namespace App\Gestion\Aid;

use App\Gestion\Loan\GestionLoanService;
use App\Gestion\Services\GestionCashService;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;

/**
 * Aides de la gestion (lot FIS-2, 21/11/2026, contrat validé par le chef, `docs/aides-gestion.md`). La fiche est stockée (`gestion_aid`) ;
 * le produit et les flux sont CALCULÉS (AidCalculator). Ce service sert l'écran (liste, fiche, conditions, justificatifs) et les autres
 * calculs de la gestion :
 * - trésorerie et rapprochement : `cashFlows` (versements en entrée, remboursement décidé en sortie) ;
 * - résultat, année, statistiques : `productBetween` (aide d'exploitation, en produit, au prorata ; deux parts, reçu et à recevoir).
 * Une avance remboursable crée un PRÊT à 0 % (lot FIS-1) lié à l'aide (`gestion_loan.aid_id`), visible dans « Prêts » : c'est lui qui
 * porte le déblocage et les remboursements. Tant que ce prêt existe, l'aide ne se supprime pas et ses termes ne changent pas
 * (`AID_LINKED_LOAN` : on modifie le prêt, ou on ferme l'aide). Une aide dans une autre devise que le site reste hors des calculs.
 */
class GestionAidService
{
    public const MAX_AMOUNT = 100_000_000_000; // 1 milliard, en centimes
    public const MAX_PAYMENTS = 24;
    public const MAX_CONDITIONS = 24;
    public const MAX_DOCUMENTS = 20;
    public const CONDITION_STATES = ['todo', 'kept', 'broken'];
    /** Ressource du journal des modifications */
    public const AUDIT = 'gestion-aides';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly GestionLoanService $loans,
        private readonly ?TenantCurrencyProvider $currency = null,
        private readonly ?ContentAuditRecorder $audit = null,
        private readonly ?\App\Gestion\Services\PeriodCalendar $calendar = null,
    ) {
    }

    /** @return list<array<string, mixed>> les aides, de la plus récente à la plus ancienne, avec leur résumé du jour */
    public function list(?string $today = null): array
    {
        $today ??= $this->today();
        $loans = $this->linkedLoans();

        return array_map(fn (array $r) => $this->summary($this->aid($r), $today, $loans), $this->conn()->fetchAllAssociative('SELECT * FROM gestion_aid ORDER BY created_at DESC, id DESC'));
    }

    /** @return array<string, mixed> */
    public function get(int $id, ?string $today = null): array
    {
        return $this->summary($this->aid($this->row($id)), $today ?? $this->today(), $this->linkedLoans());
    }

    /** @return array<string, mixed> */
    public function create(array $body, ?string $by): array
    {
        $aid = $this->validate($body, null, null);
        $id = $this->conn()->transactional(function () use ($aid, $by): int {
            $now = gmdate('Y-m-d H:i:s');
            $id = (int) $this->conn()->fetchOne('INSERT INTO gestion_aid (label, organism, kind, amount_cents, currency, status, covered_from, covered_to, payments, conditions, documents, repayment,
                advance, notes, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id', [...$this->columns($aid), $by !== null ? mb_substr($by, 0, 180) : null, $now, $now]);
            if ($aid['kind'] === AidCalculator::KIND_ADVANCE && in_array($aid['status'], AidCalculator::ENGAGED, true)) {
                $this->createLoan(['id' => $id] + $aid, $by);
            }
            $this->audit?->record(self::AUDIT, $id, 'create', null, ['id' => $id] + $this->publicAid($aid));

            return $id;
        });

        return $this->get($id);
    }

    /** @return array<string, mixed> la fiche modifiée (champs absents gardés) */
    public function update(int $id, array $body, ?string $by = null): array
    {
        $current = $this->aid($this->row($id));
        $aid = $this->validate($body + $this->publicAid($current), $id, $current);
        $loanId = $this->loanOf($id);
        if ($loanId !== null && $this->advanceTermsChanged($current, $aid)) {
            throw new GestionConflictException('AID_LINKED_LOAN', 'Cette aide a un prêt lié : modifiez le prêt, ou fermez l\'aide.', 409, ['aidId' => $id, 'loanId' => $loanId]);
        }
        $this->conn()->transactional(function () use ($id, $aid, $current, $loanId, $by): void {
            $this->conn()->executeStatement('UPDATE gestion_aid SET label = ?, organism = ?, kind = ?, amount_cents = ?, currency = ?, status = ?, covered_from = ?, covered_to = ?, payments = ?,
                conditions = ?, documents = ?, repayment = ?, advance = ?, notes = ?, updated_at = ? WHERE id = ?', [...$this->columns($aid), gmdate('Y-m-d H:i:s'), $id]);
            // l'avance devient acquise (accordée depuis « demandée ») : son prêt naît ; jamais recréé s'il a été supprimé ensuite
            if ($loanId === null && $aid['kind'] === AidCalculator::KIND_ADVANCE && in_array($aid['status'], AidCalculator::ENGAGED, true)
                && !in_array($current['status'], AidCalculator::ENGAGED, true)) {
                $this->createLoan(['id' => $id] + $aid, $by);
            }
            $this->audit?->record(self::AUDIT, $id, 'update', $this->publicAid($current), $this->publicAid($aid));
        });

        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $before = $this->publicAid($this->aid($this->row($id)));
        $loanId = $this->loanOf($id);
        if ($loanId !== null) {
            throw new GestionConflictException('AID_LINKED_LOAN', 'Cette aide a un prêt lié : supprimez d\'abord le prêt, ou fermez l\'aide.', 409, ['aidId' => $id, 'loanId' => $loanId]);
        }
        $this->conn()->transactional(function () use ($id, $before): void {
            $this->conn()->executeStatement('DELETE FROM gestion_aid_reminder WHERE aid_id = ?', [$id]);
            $this->conn()->executeStatement('DELETE FROM gestion_aid WHERE id = ?', [$id]);
            $this->audit?->record(self::AUDIT, $id, 'delete', $before, null);
        });
    }

    /** @return array<string, mixed> la fiche, une condition passée à `todo`, `kept` ou `broken` */
    public function setConditionState(int $id, int $index, mixed $state): array
    {
        $aid = $this->aid($this->row($id));
        if (!isset($aid['conditions'][$index])) {
            throw new GestionConflictException('AID_CONDITION_NOT_FOUND', 'Condition introuvable.', 404, ['index' => $index]);
        }
        if (!is_string($state) || !in_array($state, self::CONDITION_STATES, true)) {
            throw new GestionValidationException(['state' => 'AID_CONDITION_INVALID'], 'Aide invalide');
        }
        $aid['conditions'][$index]['state'] = $state;

        return $this->update($id, ['conditions' => $aid['conditions']]);
    }

    /** @return array<string, mixed> la fiche, avec un justificatif de plus (un document de la gestion existant) ; sans doublon */
    public function attachDocument(int $id, mixed $documentId): array
    {
        $aid = $this->aid($this->row($id));
        $doc = is_int($documentId) || (is_string($documentId) && ctype_digit($documentId)) ? (int) $documentId : 0;
        if ($doc < 1 || $this->conn()->fetchOne('SELECT 1 FROM gestion_document WHERE id = ?', [$doc]) === false) {
            throw new GestionValidationException(['documentId' => 'AID_DOCUMENT_NOT_FOUND'], 'Aide invalide');
        }
        if (!in_array($doc, $aid['documents'], true)) {
            $aid['documents'][] = $doc;
        }

        return $this->update($id, ['documents' => $aid['documents']]);
    }

    /** @return array<string, mixed> la fiche, sans ce justificatif (le document lui-même reste dans la gestion) */
    public function detachDocument(int $id, int $documentId): array
    {
        $aid = $this->aid($this->row($id));
        if (!in_array($documentId, $aid['documents'], true)) {
            throw new GestionConflictException('AID_DOCUMENT_NOT_FOUND', 'Ce justificatif n\'est pas rattaché à l\'aide.', 404, ['documentId' => $documentId]);
        }

        return $this->update($id, ['documents' => array_values(array_diff($aid['documents'], [$documentId]))]);
    }

    /**
     * Flux de trésorerie de toutes les aides (devise du site) entre deux jours, bornes incluses.
     *
     * @return list<array{date: string, direction: string, amount: int, kind: string, aidId: int}>
     */
    public function cashFlows(string $from, string $to): array
    {
        $out = [];
        foreach ($this->aids() as $aid) {
            foreach (AidCalculator::cashFlows($aid, $from, $to) as $f) {
                $out[] = $f + ['aidId' => $aid['id']];
            }
        }

        return $out;
    }

    /**
     * Produit des aides d'exploitation sur une plage, au prorata : `received` + `pending` − `repaid` = `total` ; `aids` : combien comptent.
     *
     * @return array{received: int, pending: int, repaid: int, total: int, aids: int}
     */
    public function productBetween(string $from, string $to): array
    {
        $sum = ['received' => 0, 'pending' => 0, 'repaid' => 0, 'total' => 0, 'aids' => 0];
        foreach ($this->aids() as $aid) {
            $p = AidCalculator::productBetween($aid, $from, $to);
            if ($p['received'] + $p['pending'] + $p['repaid'] > 0) {
                ++$sum['aids'];
            }
            foreach (['received', 'pending', 'repaid', 'total'] as $k) {
                $sum[$k] += $p[$k];
            }
        }

        return $sum;
    }

    /** Une aide dans la devise du site existe-t-elle (de celles qui entrent dans la trésorerie et le résultat) ? */
    public function hasAids(): bool
    {
        return $this->conn()->fetchOne('SELECT 1 FROM gestion_aid WHERE currency = ? LIMIT 1', [$this->siteCurrency()]) !== false;
    }

    /**
     * Remarque de double comptage : un mouvement prévu d'ENTRÉE saisi à la main, du même montant qu'un versement compté et qui tombe le même
     * mois, existe-t-il ? Rien n'est supprimé : le gérant choisit lequel garder.
     */
    public function mayOverlapMovements(): bool
    {
        $payments = [];
        foreach ($this->aids() as $aid) {
            if ($aid['kind'] !== AidCalculator::KIND_ADVANCE) {
                array_push($payments, ...AidCalculator::countedPayments($aid));
            }
        }
        if ($payments === []) {
            return false;
        }
        $movements = $this->conn()->fetchAllAssociative("SELECT amount_cents, start_date, recurrence, end_date FROM gestion_cash_movement WHERE direction = 'in'");
        foreach ($payments as $p) {
            $monthStart = substr($p['date'], 0, 8) . '01';
            $monthEnd = (new \DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
            foreach ($movements as $m) {
                if ((int) $m['amount_cents'] === $p['amount'] && GestionCashService::occurrences((string) $m['start_date'], (string) $m['recurrence'], $m['end_date'], $monthStart, $monthEnd) !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Ce qu'un rappel doit annoncer aujourd'hui, et n'a pas encore annoncé : une condition encore « à faire » arrivée à échéance, ou une aide
     * reçue (au moins un versement reçu) sans aucun justificatif. Chaque élément n'est rappelé qu'une fois (`gestion_aid_reminder`).
     *
     * @return list<array{aidId: int, item: string, kind: string, label: string, condition: ?string, dueOn: ?string}>
     */
    public function dueReminders(?string $today = null): array
    {
        $today ??= $this->today();
        $done = [];
        foreach ($this->conn()->fetchAllAssociative('SELECT aid_id, item FROM gestion_aid_reminder') as $r) {
            $done[(int) $r['aid_id'] . '|' . $r['item']] = true;
        }
        $out = [];
        foreach ($this->conn()->fetchAllAssociative("SELECT * FROM gestion_aid WHERE status <> 'closed' ORDER BY id") as $r) {
            $aid = $this->aid($r);
            foreach ($aid['conditions'] as $c) {
                $key = AidCalculator::conditionKey($c);
                if ($c['state'] === 'todo' && $c['dueOn'] <= $today && !isset($done[$aid['id'] . '|' . $key])) {
                    $out[] = ['aidId' => $aid['id'], 'item' => $key, 'kind' => 'condition', 'label' => $aid['label'], 'condition' => $c['label'], 'dueOn' => $c['dueOn']];
                }
            }
            $received = array_filter($aid['payments'], static fn (array $p): bool => $p['received']);
            if ($received !== [] && $aid['documents'] === [] && !isset($done[$aid['id'] . '|documents'])) {
                $out[] = ['aidId' => $aid['id'], 'item' => 'documents', 'kind' => 'documents', 'label' => $aid['label'], 'condition' => null, 'dueOn' => null];
            }
        }

        return $out;
    }

    /**
     * Réserve des éléments de rappel pour ce jour : rend ceux que CET appel a réservés (deux passages qui se chevauchent n'annoncent jamais
     * deux fois la même chose).
     *
     * @param list<array{aidId: int, item: string}> $items
     * @return list<array{aidId: int, item: string}>
     */
    public function claimReminders(array $items, string $day): array
    {
        $claimed = [];
        foreach ($items as $i) {
            if ($this->conn()->executeStatement('INSERT INTO gestion_aid_reminder (aid_id, item, sent_on) VALUES (?, ?, ?) ON CONFLICT DO NOTHING', [$i['aidId'], $i['item'], $day]) === 1) {
                $claimed[] = $i;
            }
        }

        return $claimed;
    }

    /** @param list<array{aidId: int, item: string}> $items rendus : rien n'est parti ni retenu, on réessaiera au prochain passage */
    public function releaseReminders(array $items): void
    {
        foreach ($items as $i) {
            $this->conn()->executeStatement('DELETE FROM gestion_aid_reminder WHERE aid_id = ? AND item = ?', [$i['aidId'], $i['item']]);
        }
    }

    /**
     * Aides qui entrent dans la trésorerie et le résultat : celles dans la devise du site (aucune conversion, comme les prêts).
     *
     * @return list<array<string, mixed>>
     */
    private function aids(): array
    {
        return array_map(fn (array $r) => $this->aid($r), $this->conn()->fetchAllAssociative('SELECT * FROM gestion_aid WHERE currency = ? ORDER BY id', [$this->siteCurrency()]));
    }

    /**
     * Résumé d'une aide : reçu, encore à recevoir, conditions échues, justificatif manquant, prêt lié.
     *
     * @param array<int, array{id: int, label: string}> $loans aide → prêt lié
     * @return array<string, mixed>
     */
    private function summary(array $aid, string $today, array $loans): array
    {
        $received = array_sum(array_map(static fn (array $p): int => $p['received'] ? $p['amount'] : 0, $aid['payments']));
        $due = array_values(array_filter($aid['conditions'], static fn (array $c): bool => $c['state'] === 'todo' && $c['dueOn'] <= $today));

        $loan = $loans[$aid['id']] ?? null;

        return $this->publicAid($aid) + [
            'loanId' => $loan['id'] ?? null,
            'loan' => $loan, // { id, label } du prêt lié (avance remboursable), ou null
            'receivedTotal' => $received,
            'toReceive' => in_array($aid['status'], [AidCalculator::STATUS_GRANTED, AidCalculator::STATUS_RECEIVED, AidCalculator::STATUS_TO_REPAY], true) ? max(0, $aid['amount'] - $received) : 0,
            'conditionsDue' => count($due),
            'documentsMissing' => $received > 0 && $aid['documents'] === [],
            'inCalculations' => $aid['currency'] === $this->siteCurrency(),
        ];
    }

    /** @return array<string, mixed> la fiche telle que les applications la lisent */
    private function publicAid(array $aid): array
    {
        return array_intersect_key($aid, array_flip(['id', 'label', 'organism', 'kind', 'amount', 'currency', 'status', 'coveredFrom', 'coveredTo', 'payments', 'conditions', 'documents',
            'repayment', 'advance', 'notes']));
    }

    /**
     * Vérifie une fiche complète ; un refus nomme chaque champ avec un code stable (422 VALIDATION_FAILED).
     *
     * @return array<string, mixed>
     * @throws GestionValidationException
     */
    private function validate(array $b, ?int $id, ?array $current): array
    {
        $errors = [];
        $date = static fn ($v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && \DateTimeImmutable::createFromFormat('!Y-m-d', $v)?->format('Y-m-d') === $v ? $v : null;
        $int = static fn ($v): ?int => is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', $v)) ? (int) $v : null;
        $label = trim(strip_tags((string) ($b['label'] ?? '')));
        if ($label === '' || mb_strlen($label) > 120) {
            $errors['label'] = 'AID_LABEL_INVALID';
        }
        $kind = $b['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, AidCalculator::KINDS, true)) {
            $errors['kind'] = 'AID_KIND_INVALID';
            $kind = null;
        }
        $amount = $int($b['amount'] ?? null);
        if ($amount === null || $amount < 1 || $amount > self::MAX_AMOUNT) {
            $errors['amount'] = 'AID_AMOUNT_INVALID';
        }
        $status = $b['status'] ?? AidCalculator::STATUS_REQUESTED;
        if (!is_string($status) || !in_array($status, AidCalculator::STATUSES, true)) {
            $errors['status'] = 'AID_STATUS_INVALID';
            $status = null;
        }
        $currency = strtoupper(trim((string) ($b['currency'] ?? $this->siteCurrency())));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors['currency'] = 'AID_CURRENCY_INVALID';
        }
        // période couverte : les deux bornes, ou aucune
        $coveredFrom = ($b['coveredFrom'] ?? null) === null ? null : $date($b['coveredFrom']);
        $coveredTo = ($b['coveredTo'] ?? null) === null ? null : $date($b['coveredTo']);
        if ((($b['coveredFrom'] ?? null) !== null && $coveredFrom === null) || (($b['coveredTo'] ?? null) !== null && $coveredTo === null)
            || (($coveredFrom === null) !== ($coveredTo === null)) || ($coveredFrom !== null && $coveredTo !== null && $coveredFrom > $coveredTo)) {
            $errors['coveredFrom'] = 'AID_DATE_INVALID';
        }
        $payments = [];
        $raw = $b['payments'] ?? [];
        if (!is_array($raw) || count($raw) > self::MAX_PAYMENTS) {
            $errors['payments'] = 'AID_PAYMENT_INVALID';
        } else {
            foreach (array_values($raw) as $p) {
                $pd = is_array($p) ? $date($p['date'] ?? null) : null;
                $pa = is_array($p) ? $int($p['amount'] ?? null) : null;
                $pr = is_array($p) ? ($p['received'] ?? false) : null;
                if ($pd === null || $pa === null || $pa < 1 || !is_bool($pr)) {
                    $errors['payments'] = 'AID_PAYMENT_INVALID';
                    break;
                }
                $payments[] = ['date' => $pd, 'amount' => $pa, 'received' => $pr];
            }
            if (!isset($errors['payments']) && $amount !== null && array_sum(array_column($payments, 'amount')) > $amount) {
                $errors['payments'] = 'AID_PAYMENT_INVALID'; // jamais plus versé que le montant accordé
            }
        }
        $conditions = [];
        $raw = $b['conditions'] ?? [];
        if (!is_array($raw) || count($raw) > self::MAX_CONDITIONS) {
            $errors['conditions'] = 'AID_CONDITION_INVALID';
        } else {
            foreach (array_values($raw) as $c) {
                $cl = is_array($c) ? trim(strip_tags((string) ($c['label'] ?? ''))) : '';
                $cd = is_array($c) ? $date($c['dueOn'] ?? null) : null;
                $cs = is_array($c) ? ($c['state'] ?? 'todo') : null;
                if ($cl === '' || mb_strlen($cl) > 200 || $cd === null || !is_string($cs) || !in_array($cs, self::CONDITION_STATES, true)) {
                    $errors['conditions'] = 'AID_CONDITION_INVALID';
                    break;
                }
                $conditions[] = ['label' => $cl, 'dueOn' => $cd, 'state' => $cs];
            }
        }
        $documents = [];
        $raw = $b['documents'] ?? [];
        if (!is_array($raw) || count($raw) > self::MAX_DOCUMENTS) {
            $errors['documents'] = 'AID_DOCUMENT_NOT_FOUND';
        } else {
            foreach ($raw as $d) {
                $di = $int($d);
                if ($di === null || $di < 1) {
                    $errors['documents'] = 'AID_DOCUMENT_NOT_FOUND';
                    break;
                }
                $documents[$di] = $di;
            }
            $documents = array_values($documents);
            // seuls les justificatifs NOUVEAUX sont relus : un document retiré de la gestion depuis ne bloque pas une autre modification
            $added = array_values(array_diff($documents, $current['documents'] ?? []));
            if (!isset($errors['documents']) && $added !== []
                && (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM gestion_document WHERE id IN (?)', [$added], [\Doctrine\DBAL\ArrayParameterType::INTEGER]) !== count($added)) {
                $errors['documents'] = 'AID_DOCUMENT_NOT_FOUND';
            }
        }
        // remboursement décidé (une condition non tenue) : exigé pour une aide « à rembourser » qui n'est pas une avance
        $repayment = null;
        $raw = $b['repayment'] ?? null;
        if ($raw !== null) {
            $ra = is_array($raw) ? $int($raw['amount'] ?? null) : null;
            $rd = is_array($raw) ? $date($raw['dueOn'] ?? null) : null;
            $rdec = is_array($raw) ? (($raw['decidedOn'] ?? null) === null ? ($current['repayment']['decidedOn'] ?? $this->today()) : $date($raw['decidedOn'])) : null;
            if ($ra === null || $ra < 1 || ($amount !== null && $ra > $amount) || $rd === null || $rdec === null) {
                $errors['repayment'] = 'AID_REPAYMENT_INVALID';
            } else {
                $repayment = ['amount' => $ra, 'dueOn' => $rd, 'decidedOn' => $rdec];
            }
        }
        if ($kind !== null && $kind !== AidCalculator::KIND_ADVANCE && $status === AidCalculator::STATUS_TO_REPAY && $repayment === null && !isset($errors['repayment'])) {
            $errors['repayment'] = 'AID_REPAYMENT_INVALID';
        }
        // termes de l'avance remboursable (son prêt à 0 %) ; son déblocage est le premier versement
        $advance = null;
        if ($kind === AidCalculator::KIND_ADVANCE) {
            $raw = $b['advance'] ?? null;
            $ad = is_array($raw) ? $int($raw['durationMonths'] ?? null) : null;
            $af = is_array($raw) ? $int($raw['frequencyMonths'] ?? 1) : null;
            $afd = is_array($raw) ? $date($raw['firstDueOn'] ?? null) : null;
            $adef = is_array($raw) ? $int($raw['deferralMonths'] ?? 0) : null;
            if ($ad === null || $ad < 1 || $af === null || !in_array($af, GestionLoanService::FREQUENCIES, true) || $afd === null || $adef === null || $adef < 0) {
                $errors['advance'] = 'AID_ADVANCE_INVALID';
            } elseif ($payments === [] && !isset($errors['payments'])) {
                $errors['payments'] = 'AID_PAYMENT_INVALID'; // le versement de l'avance est le déblocage de son prêt
            } else {
                $advance = ['durationMonths' => $ad, 'frequencyMonths' => $af, 'firstDueOn' => $afd, 'deferralMonths' => $adef];
            }
            $repayment = null; // une avance se rembourse par son prêt
        }
        // une aide d'exploitation se compte au prorata de sa période couverte, sinon à la date de ses versements : l'un ou l'autre
        if ($kind === AidCalculator::KIND_OPERATING && $coveredFrom === null && $payments === [] && !isset($errors['coveredFrom']) && !isset($errors['payments'])) {
            $errors['coveredFrom'] = 'AID_PERIOD_REQUIRED';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Aide invalide');
        }
        usort($payments, static fn ($x, $y) => strcmp($x['date'], $y['date']));
        usort($conditions, static fn ($x, $y) => strcmp($x['dueOn'], $y['dueOn']));
        $organism = trim(strip_tags((string) ($b['organism'] ?? '')));
        $notes = trim(strip_tags((string) ($b['notes'] ?? '')));

        return ['id' => $id, 'label' => $label, 'organism' => $organism !== '' ? mb_substr($organism, 0, 120) : null, 'kind' => $kind, 'amount' => $amount, 'currency' => $currency,
            'status' => $status, 'coveredFrom' => $coveredFrom, 'coveredTo' => $coveredTo, 'payments' => $payments, 'conditions' => $conditions, 'documents' => $documents,
            'repayment' => $repayment, 'advance' => $advance, 'notes' => $notes !== '' ? mb_substr($notes, 0, 1000) : null];
    }

    /** Le prêt à 0 % d'une avance remboursable, lié à l'aide ; un refus du prêt est rendu sur `advance` */
    private function createLoan(array $aid, ?string $by): void
    {
        $terms = $aid['advance'];
        try {
            $loan = $this->loans->create(['label' => $aid['label'], 'lender' => $aid['organism'], 'amount' => $aid['amount'], 'currency' => $aid['currency'], 'ratePpm' => 0,
                'durationMonths' => $terms['durationMonths'], 'frequencyMonths' => $terms['frequencyMonths'], 'disbursedOn' => $aid['payments'][0]['date'], 'firstDueOn' => $terms['firstDueOn'],
                'type' => 'annuity', 'deferralMonths' => $terms['deferralMonths'], 'deferralKind' => $terms['deferralMonths'] > 0 ? 'partial' : null,
                'notes' => sprintf('Avance remboursable (aide n° %d)', $aid['id'])], $by);
        } catch (GestionValidationException $e) {
            throw new GestionValidationException(['advance' => 'AID_ADVANCE_INVALID'] + array_combine(array_map(static fn ($k) => 'advance.' . $k, array_keys($e->fields)), array_values($e->fields)), 'Aide invalide');
        }
        $this->conn()->executeStatement('UPDATE gestion_loan SET aid_id = ? WHERE id = ?', [$aid['id'], $loan['id']]);
    }

    /** Une avance dont le prêt existe garde ses termes : genre, montant, devise, premier versement (le déblocage) et termes du prêt */
    private function advanceTermsChanged(array $before, array $after): bool
    {
        return $before['kind'] !== $after['kind'] || $before['amount'] !== $after['amount'] || $before['currency'] !== $after['currency'] || $before['advance'] != $after['advance']
            || ($before['payments'][0]['date'] ?? null) !== ($after['payments'][0]['date'] ?? null);
    }

    private function loanOf(int $aidId): ?int
    {
        $id = $this->conn()->fetchOne('SELECT id FROM gestion_loan WHERE aid_id = ? ORDER BY id LIMIT 1', [$aidId]);

        return $id === false ? null : (int) $id;
    }

    /** @return array<int, array{id: int, label: string}> aide → prêt lié (le plus récent) */
    private function linkedLoans(): array
    {
        $out = [];
        foreach ($this->conn()->fetchAllAssociative('SELECT id, aid_id, label FROM gestion_loan WHERE aid_id IS NOT NULL ORDER BY id') as $r) {
            $out[(int) $r['aid_id']] = ['id' => (int) $r['id'], 'label' => (string) $r['label']];
        }

        return $out;
    }

    /** @return list<mixed> les colonnes d'écriture, dans l'ordre des requêtes */
    private function columns(array $a): array
    {
        $json = static fn ($v) => $v === null || $v === [] ? null : json_encode($v);

        return [$a['label'], $a['organism'], $a['kind'], $a['amount'], $a['currency'], $a['status'], $a['coveredFrom'], $a['coveredTo'], $json($a['payments']), $json($a['conditions']),
            $json($a['documents']), $json($a['repayment']), $json($a['advance']), $a['notes']];
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $r = $this->conn()->fetchAssociative('SELECT * FROM gestion_aid WHERE id = ?', [$id]);
        if ($r === false) {
            throw new GestionConflictException('AID_NOT_FOUND', 'Aide introuvable.', 404, ['aidId' => $id]);
        }

        return $r;
    }

    /** @return array<string, mixed> la fiche au format du calcul */
    private function aid(array $r): array
    {
        $json = static fn ($v) => is_string($v) ? (json_decode($v, true) ?: null) : null;

        return ['id' => (int) $r['id'], 'label' => (string) $r['label'], 'organism' => $r['organism'], 'kind' => (string) $r['kind'], 'amount' => (int) $r['amount_cents'],
            'currency' => (string) $r['currency'], 'status' => (string) $r['status'], 'coveredFrom' => $r['covered_from'], 'coveredTo' => $r['covered_to'],
            'payments' => $json($r['payments']) ?? [], 'conditions' => $json($r['conditions']) ?? [], 'documents' => array_map('intval', $json($r['documents']) ?? []),
            'repayment' => $json($r['repayment']), 'advance' => $json($r['advance']), 'notes' => $r['notes']];
    }

    private function siteCurrency(): string
    {
        return $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT;
    }

    /** Le jour du magasin (fuseau du site) : jamais le jour UTC */
    private function today(): string
    {
        return $this->calendar?->today()->format('Y-m-d') ?? gmdate('Y-m-d');
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
