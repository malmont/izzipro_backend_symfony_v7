<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\Entity\LossEvidence;
use App\LossPrevention\Entity\LossFinding;
use App\LossPrevention\Entity\LossJournalEntry;
use App\LossPrevention\Repository\LossFindingRepository;
use App\Services\TenantEntityManagerProvider;

/**
 * Lecture des constats pour les écrans (`docs/prevention-pertes.md` § 5 et 6) : tout est rédigé par le serveur dans la langue demandée.
 * - Le résumé d'un constat ne nomme JAMAIS personne : il est écrit à partir des nombres gardés dans `facts`. La liste et la synthèse sont
 *   donc sans nom. Les noms (jamais les courriels) n'apparaissent que dans la fiche d'un constat : `onDuty`, les lignes des preuves, et le
 *   sujet d'un constat « par personne ».
 * - Une preuve est rendue en lignes prêtes à afficher (`lines [{ label, value }]`) : l'écran n'interprète aucune donnée brute.
 */
class LossFindingReader
{
    public const SCREEN = 'pertes.constat';
    private const LIMIT_MAX = 100;
    private const PAGE_MAX = 100000;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly LossTexts $texts, private readonly LossSite $site)
    {
    }

    /**
     * @param array<string, mixed> $query paramètres de la requête : status (plusieurs, séparés par des virgules), rule, from, to, cashRegisterId, isNew, sort, page, limit
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, limit: int, filters: array<string, mixed>}
     */
    public function list(array $query, string $locale): array
    {
        $errors = [];
        // un paramètre répété ou en tableau (`status[]=`) n'est pas une valeur : il est refusé, jamais converti
        foreach (['status', 'rule', 'sort', 'isNew', 'cashRegisterId', 'from', 'to', 'page', 'limit'] as $key) {
            if (isset($query[$key]) && !is_scalar($query[$key])) {
                $errors[$key] = 'INVALID_VALUE';
                unset($query[$key]);
            }
        }
        $statuses = array_values(array_filter(array_map('trim', explode(',', strtolower((string) ($query['status'] ?? ''))))));
        if (array_diff($statuses, LossFinding::STATUSES) !== []) {
            $errors['status'] = 'INVALID_VALUE';
        }
        $rule = isset($query['rule']) && $query['rule'] !== '' ? strtoupper((string) $query['rule']) : null;
        if ($rule !== null && !LossRuleCatalog::exists($rule)) {
            $errors['rule'] = 'INVALID_VALUE';
        }
        $days = [];
        foreach (['from', 'to'] as $key) {
            $days[$key] = isset($query[$key]) && $query[$key] !== '' ? $query[$key] : null;
            if ($days[$key] !== null && (!is_string($days[$key]) || !self::isDay($days[$key]))) {
                $errors[$key] = 'INVALID_DATE';
            }
        }
        $sort = isset($query['sort']) && $query['sort'] !== '' ? strtolower((string) $query['sort']) : 'date';
        if (!isset(LossFindingRepository::SORTS[$sort])) {
            $errors['sort'] = 'INVALID_VALUE';
        }
        $isNew = isset($query['isNew']) && $query['isNew'] !== '' ? filter_var($query['isNew'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        if (isset($query['isNew']) && $query['isNew'] !== '' && $isNew === null) {
            $errors['isNew'] = 'MUST_BE_BOOLEAN';
        }
        // page et limite : des chiffres, sinon 422 ; une valeur démesurée est ramenée à la borne
        $number = function (string $key, int $default, int $max) use ($query, &$errors): int {
            if (!isset($query[$key]) || $query[$key] === '') {
                return $default;
            }
            if (!ctype_digit((string) $query[$key])) {
                $errors[$key] = 'INVALID_VALUE';

                return $default;
            }

            return max(1, strlen((string) $query[$key]) > 9 ? $max : min($max, (int) $query[$key]));
        };
        $page = $number('page', 1, self::PAGE_MAX);
        $limit = $number('limit', 20, self::LIMIT_MAX);
        if (isset($query['cashRegisterId']) && $query['cashRegisterId'] !== '' && (!ctype_digit((string) $query['cashRegisterId']) || strlen((string) $query['cashRegisterId']) > 9)) {
            $errors['cashRegisterId'] = 'INVALID_VALUE';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $filters = ['statuses' => $statuses, 'rule' => $rule, 'from' => $days['from'], 'to' => $days['to'],
            'cashRegisterId' => isset($query['cashRegisterId']) && ctype_digit((string) $query['cashRegisterId']) && strlen((string) $query['cashRegisterId']) <= 9 ? (int) $query['cashRegisterId'] : null, 'isNew' => $isNew];
        [$rows, $total] = $this->findings()->search($filters, $sort, $page, $limit);
        $registers = $this->registerNames(array_column($rows, 'cash_register_id'));

        return ['rows' => array_map(fn (array $r) => $this->line($r, $registers, $locale), $rows), 'total' => $total, 'page' => $page, 'limit' => $limit, 'filters' => $filters + ['sort' => $sort]];
    }

    /**
     * La fiche d'un constat. `$viewerId` : l'administrateur qui l'ouvre (consultation journalisée une fois par jour, « nouveau » éteint) ; null pour une lecture
     * interne (réponse d'une décision), qui ne journalise rien.
     *
     * @return array<string, mixed>
     */
    public function detail(int $id, string $locale, ?int $viewerId): array
    {
        $row = $this->findings()->row($id);
        if ($row === null) {
            throw new GestionConflictException('FINDING_NOT_FOUND', 'Constat inconnu.', 404);
        }
        $em = $this->emProvider->getEntityManager();
        if ($viewerId !== null) {
            $this->findings()->markSeen($id, $viewerId);
            // consultation journalisée une fois par administrateur et par journée du magasin : le journal est inaltérable et ne se purge pas,
            // un écran qui se rafraîchit ne doit pas le faire grossir
            $journalRepo = $em->getRepository(LossJournalEntry::class);
            [$dayStart] = $this->site->clock()->range(null, null);
            if (!$journalRepo->existsSince(LossJournalEntry::FINDING_VIEWED, $viewerId, $id, $dayStart)) {
                $journalRepo->append(LossJournalEntry::FINDING_VIEWED, $viewerId, $id);
            }
            $row = $this->findings()->row($id) ?? $row;
        }
        $evidenceRows = $em->getRepository(LossEvidence::class)->forFinding($id);
        $journal = $em->getRepository(LossJournalEntry::class)->forFinding($id);
        $onDuty = json_decode((string) $row['on_duty'], true) ?: [];
        $userIds = array_column($onDuty, 'id');
        $snapshots = [];
        foreach ($evidenceRows as $e) {
            $snapshots[$e['id']] = json_decode((string) $e['snapshot'], true) ?: [];
            foreach (['closedById', 'userId', 'approvedById', 'sellerId', 'canceledById'] as $key) {
                $userIds[] = $snapshots[$e['id']][$key] ?? null;
            }
        }
        $userIds[] = $row['decided_by'];
        if ($row['subject_type'] === 'user_window') {
            $userIds[] = $row['subject_id'];
        }
        foreach ($journal as $j) {
            $userIds[] = $j['user_id'];
        }
        $names = $this->userNames($userIds, $locale);
        $reasons = $this->liveReasons($evidenceRows);
        $tz = $this->site->clock()->timezone();
        $intact = true;
        $evidence = [];
        foreach ($evidenceRows as $e) {
            $intact = $intact && hash('sha256', (string) $e['snapshot']) === $e['fingerprint'];
            $at = LossAuditEntry::utc((string) $e['occurred_at']);
            $evidence[] = [
                'id' => (int) $e['id'], 'kind' => (string) $e['kind'], 'at' => LossAuditEntry::iso($at), 'atLabel' => LossTexts::dateTime($at, $tz, $locale),
                'title' => $this->texts->t('evidence.' . $e['kind'] . '.title', $locale),
                'lines' => $this->lines((string) $e['kind'], $snapshots[$e['id']] + ['liveReason' => $reasons[$e['id']] ?? null], (string) $row['currency'], $names, $tz, $locale),
                'ref' => ['type' => (string) $e['ref_type'], 'id' => (int) $e['ref_id'], 'sequence' => $e['audit_sequence'] !== null ? (int) $e['audit_sequence'] : null, 'hash' => $e['audit_hash']],
                'screen' => null, // aucun écran de la gestion pour une vente au comptoir, une session ou une clôture (lot L1)
                'fingerprint' => (string) $e['fingerprint'],
            ];
        }
        $history = [];
        foreach ($journal as $j) {
            if ($j['type'] === LossJournalEntry::DECISION_CHANGED) {
                $data = json_decode((string) $j['data'], true) ?: [];
                $history[] = ['at' => LossAuditEntry::iso(LossAuditEntry::utc((string) $j['occurred_at'])), 'status' => $data['status'] ?? null, 'previous' => $data['previous'] ?? null,
                    'statusLabel' => isset($data['status']) ? $this->texts->t('status.' . $data['status'], $locale) : null, 'hasNote' => (bool) ($data['hasNote'] ?? false),
                    'by' => $j['user_id'] !== null ? ['id' => (int) $j['user_id'], 'name' => $names[(int) $j['user_id']]] : null];
            }
        }
        $registers = $this->registerNames([$row['cash_register_id']]);
        $subject = ['type' => (string) $row['subject_type'], 'id' => (int) $row['subject_id']];
        if ($row['subject_type'] === 'user_window') {
            $subject += ['name' => $names[(int) $row['subject_id']], 'label' => $this->texts->t('subject.user_window', $locale)];
        }

        return $this->line($row, $registers, $locale) + [
            'from' => LossAuditEntry::iso(LossAuditEntry::utc((string) $row['occurred_from'])), 'to' => LossAuditEntry::iso(LossAuditEntry::utc((string) $row['occurred_to'])),
            'sessionId' => $row['session_id'] !== null ? (int) $row['session_id'] : null,
            'subject' => $subject,
            'onDuty' => array_map(fn (array $p) => ['id' => (int) $p['id'], 'name' => $names[(int) $p['id']], 'role' => (string) $p['role'], 'roleLabel' => $this->texts->t('role.' . $p['role'], $locale)], $onDuty),
            'onDutyNotice' => $this->texts->t('onDutyNotice', $locale),
            'amountNotice' => $this->texts->t('amountNotice', $locale),
            'evidence' => $evidence,
            'evidenceIntact' => $intact,
            'opinion' => null, // avis (lot L5)
            'decision' => $row['decided_at'] === null ? null : [
                'status' => (string) $row['status'], 'note' => $row['decision_note'], 'at' => LossAuditEntry::iso(LossAuditEntry::utc((string) $row['decided_at'])),
                'by' => $row['decided_by'] !== null ? ['id' => (int) $row['decided_by'], 'name' => $names[(int) $row['decided_by']]] : null],
            'history' => $history,
            'lastEvidenceAt' => LossAuditEntry::iso(LossAuditEntry::utc((string) $row['last_evidence_at'])),
            'createdAt' => LossAuditEntry::iso(LossAuditEntry::utc((string) $row['created_at'])),
            'screen' => self::SCREEN,
        ];
    }

    /**
     * Décision du gérant : `{ status, note? }`. Note obligatoire pour « expliqué » et « confirmé » ; revenir à « nouveau » est permis ; chaque
     * changement est gardé au journal du module (qui, quand, avant, après, avec ou sans note ; jamais le texte de la note).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed> le constat complet
     */
    public function decide(int $id, array $body, int $userId, string $locale): array
    {
        $row = $this->findings()->row($id);
        if ($row === null) {
            throw new GestionConflictException('FINDING_NOT_FOUND', 'Constat inconnu.', 404);
        }
        $status = is_string($body['status'] ?? null) ? strtolower(trim($body['status'])) : '';
        $note = is_string($body['note'] ?? null) ? trim($body['note']) : '';
        $errors = [];
        if (!in_array($status, LossFinding::STATUSES, true)) {
            $errors['status'] = 'INVALID_VALUE';
        } elseif ($note === '' && in_array($status, LossFinding::NOTE_REQUIRED, true)) {
            $errors['note'] = 'REQUIRED';
        }
        if (isset($body['note']) && !is_string($body['note'])) {
            $errors['note'] = 'MUST_BE_STRING';
        } elseif (mb_strlen($note) > 2000) {
            $errors['note'] = 'TOO_LONG';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $note = $status === LossFinding::OPEN || $note === '' ? null : $note;
        $this->findings()->markSeen($id, $userId); // décider, c'est avoir vu : un constat décidé n'est plus « nouveau »
        if ($status !== $row['status'] || $note !== $row['decision_note']) {
            $this->findings()->decide($id, $status, $note, $userId);
            // Au journal (inaltérable) : qui, quand, avant, après, avec ou sans note. Le TEXTE de la note (libre) n'y entre pas : il ne vit que
            // sur le constat, dans sa dernière version, où il reste corrigeable (choix C du 14/11/2026)
            $this->emProvider->getEntityManager()->getRepository(LossJournalEntry::class)->append(LossJournalEntry::DECISION_CHANGED, $userId, $id, ['previous' => (string) $row['status'], 'status' => $status, 'hasNote' => $note !== null]);
        }

        return $this->detail($id, $locale, null);
    }

    /**
     * Ligne d'un constat (liste, et base de la fiche) : sans aucun nom.
     *
     * @param array<string, mixed> $row
     * @param array<int, string> $registers
     * @return array<string, mixed>
     */
    private function line(array $row, array $registers, string $locale): array
    {
        $register = $row['cash_register_id'] !== null ? (int) $row['cash_register_id'] : null;
        $person = $row['variant'] === LossFinding::VARIANT_PERSON;

        return [
            'id' => (int) $row['id'], 'number' => (int) $row['number'],
            'rule' => ['code' => (string) $row['rule_code'], 'label' => $this->texts->t('rules.' . $row['rule_code'] . ($person ? '.person.label' : '.label'), $locale)],
            'personPattern' => $person,
            'status' => (string) $row['status'], 'statusLabel' => $this->texts->t('status.' . $row['status'], $locale),
            'summary' => $this->summary($row, $locale),
            'storeDay' => (string) $row['store_day'],
            'cashRegister' => $register !== null ? ['id' => $register, 'name' => $registers[$register] ?? null] : null,
            'amountAtStake' => $row['amount_at_stake'] !== null ? (int) $row['amount_at_stake'] : null, 'currency' => (string) $row['currency'],
            'isNew' => $row['seen_at'] === null && !$row['backfill'],
            'newEvidenceSinceDecision' => $row['decided_at'] !== null && (string) $row['last_evidence_at'] > (string) $row['decided_at'],
            'backfill' => (bool) $row['backfill'],
            'updatedAt' => LossAuditEntry::iso(LossAuditEntry::utc((string) $row['updated_at'])),
        ];
    }

    /** Phrase de résumé, écrite à partir des nombres du constat : aucun nom, aucun courriel @param array<string, mixed> $row */
    private function summary(array $row, string $locale): string
    {
        $facts = json_decode((string) $row['facts'], true) ?: [];
        $money = fn (mixed $cents) => LossTexts::money((int) $cents, $locale, (string) $row['currency']);
        $code = (string) $row['rule_code'];
        if ($row['variant'] === LossFinding::VARIANT_PERSON) {
            return $this->texts->t("summary.$code.person", $locale, ['count' => (int) ($facts['count'] ?? 0), 'days' => (int) ($facts['days'] ?? 0), 'amount' => $money($facts['total'] ?? 0)]);
        }

        return match ($code) {
            LossRuleCatalog::CASH_DISCREPANCY => $this->texts->t("summary.$code." . (($facts['discrepancy'] ?? 0) < 0 ? 'short' : 'over'), $locale, [
                'number' => (int) ($facts['reportNumber'] ?? 0), 'amount' => $money(abs((int) ($facts['discrepancy'] ?? 0))),
                'expected' => $money($facts['expectedCash'] ?? 0), 'counted' => $money($facts['countedCash'] ?? 0)]),
            LossRuleCatalog::DRAWER_NO_SALE => $this->texts->t("summary.$code.rule", $locale, ['count' => (int) ($facts['count'] ?? 0), 'max' => (int) ($facts['max'] ?? 0)]),
            LossRuleCatalog::VOID_AFTER_SALE => ($facts['case'] ?? '') === 'series'
                ? $this->texts->t("summary.$code.series", $locale, ['count' => (int) ($facts['count'] ?? 0), 'amount' => $money($facts['total'] ?? 0)])
                : $this->texts->t("summary.$code.quick", $locale, ['amount' => $money($facts['total'] ?? 0), 'delay' => (int) ($facts['delayMinutes'] ?? 0)]),
            default => $this->texts->t('summary.default', $locale),
        };
    }

    /**
     * Lignes prêtes à afficher d'une preuve ; une valeur absente de la copie n'a pas de ligne.
     *
     * @param array<string, mixed> $s copie figée
     * @param array<int, string> $names
     * @return list<array{label: string, value: string}>
     */
    private function lines(string $kind, array $s, string $currency, array $names, \DateTimeZone $tz, string $locale): array
    {
        $money = fn (string $key) => isset($s[$key]) ? LossTexts::money((int) $s[$key], $locale, $currency) : null;
        $user = fn (string $key) => isset($s[$key]) ? ($names[(int) $s[$key]] ?? null) : null;
        $when = fn (string $key) => isset($s[$key]) ? LossTexts::dateTime(new \DateTimeImmutable((string) $s[$key]), $tz, $locale) : null;
        $text = fn (string $key) => isset($s[$key]) && $s[$key] !== '' ? (string) $s[$key] : null;
        $values = match ($kind) {
            LossEvidence::Z_REPORT => ['reportNumber' => $text('reportNumber'), 'closedAt' => $when('closedAt'), 'closedBy' => $user('closedById'), 'expectedCash' => $money('expectedCash'),
                'countedCash' => $money('countedCash'), 'discrepancy' => $money('discrepancy'), 'discrepancyReason' => $text('liveReason') ?? $this->texts->t('evidence.noReason', $locale),
                'keptFund' => $money('keptFund'), 'bankDeposit' => $money('bankDeposit')],
            LossEvidence::DRAWER => ['openedBy' => $user('userId'), 'reason' => $text('liveReason') ?? $this->texts->t('evidence.noReason', $locale), 'approvedBy' => $user('approvedById')],
            LossEvidence::ORDER => ['reference' => $text('reference'), 'soldAt' => $when('soldAt'), 'total' => $money('total'), 'cashPart' => $money('cashPart'), 'seller' => $user('sellerId')],
            LossEvidence::CREDIT_NOTE => ['reference' => $text('reference'), 'soldAt' => $when('soldAt'), 'canceledAt' => $when('canceledAt'), 'creditNoteNumber' => $text('creditNoteNumber'),
                'refundAmount' => $money('refundAmount'), 'cashRefund' => $money('cashRefund'), 'canceledBy' => $user('canceledById'), 'approvedBy' => $user('approvedById')],
            default => [],
        };
        $lines = [];
        foreach ($values as $key => $value) {
            if ($value !== null) {
                $lines[] = ['label' => $this->texts->t("evidence.lines.$key", $locale), 'value' => $value];
            }
        }

        return $lines;
    }

    /**
     * Motifs saisis à la caisse (texte libre du caissier), lus À LA SOURCE au moment de l'affichage : le motif d'écart dans le rapport de
     * clôture, le motif d'ouverture du tiroir dans l'entrée du journal de caisse. Ils ne sont jamais recopiés dans les preuves, qui sont
     * inaltérables (choix C du 14/11/2026) : la caisse reste le seul endroit qui les garde.
     *
     * @param list<array<string, mixed>> $evidenceRows
     * @return array<int|string, ?string> identifiant de la preuve => motif
     */
    private function liveReasons(array $evidenceRows): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $byKind = [LossEvidence::Z_REPORT => [], LossEvidence::DRAWER => []];
        foreach ($evidenceRows as $e) {
            if (isset($byKind[$e['kind']])) {
                $byKind[$e['kind']][(int) $e['ref_id']] = $e['id'];
            }
        }
        $out = [];
        if ($byKind[LossEvidence::Z_REPORT] !== []) {
            foreach ($conn->fetchAllKeyValue('SELECT id, discrepancy_reason FROM cash_register_report WHERE id IN (' . implode(',', array_keys($byKind[LossEvidence::Z_REPORT])) . ')') as $id => $reason) {
                $out[$byKind[LossEvidence::Z_REPORT][(int) $id]] = trim((string) $reason) !== '' ? (string) $reason : null;
            }
        }
        if ($byKind[LossEvidence::DRAWER] !== []) {
            foreach ($conn->fetchAllKeyValue('SELECT id, payload FROM pos_audit_log WHERE id IN (' . implode(',', array_keys($byKind[LossEvidence::DRAWER])) . ')') as $id => $payload) {
                $reason = (json_decode((string) $payload, true) ?: [])['data']['reason'] ?? null;
                $out[$byKind[LossEvidence::DRAWER][(int) $id]] = is_string($reason) && trim($reason) !== '' ? $reason : null;
            }
        }

        return $out;
    }

    /**
     * Noms affichables des comptes : jamais le courriel ; un compte sans nom sort en « Compte n° … ».
     *
     * @param list<mixed> $ids
     * @return array<int, string>
     */
    private function userNames(array $ids, string $locale): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, fn ($v) => $v !== null))));
        $names = [];
        if ($ids !== []) {
            foreach ($this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative('SELECT id, firstname, lastname FROM "user" WHERE id IN (' . implode(',', $ids) . ')') as $u) {
                $names[(int) $u['id']] = trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? ''));
            }
        }
        foreach ($ids as $id) {
            if (($names[$id] ?? '') === '') {
                $names[$id] = $this->texts->t('user.unnamed', $locale, ['id' => $id]);
            }
        }

        return $names;
    }

    /**
     * @param list<mixed> $ids
     * @return array<int, string>
     */
    private function registerNames(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, fn ($v) => $v !== null))));

        return $ids === [] ? [] : array_map('strval', $this->emProvider->getEntityManager()->getConnection()->fetchAllKeyValue('SELECT id, name FROM cash_register WHERE id IN (' . implode(',', $ids) . ')'));
    }

    public static function isDay(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }

    private function findings(): LossFindingRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossFinding::class);
    }
}
