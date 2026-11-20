<?php

namespace App\Abo\Services;

use App\Abo\Entity\BenefitOccurrence;
use App\Abo\Entity\BenefitSlot;
use App\Abo\Entity\SubscriptionAboEvent;
use App\Entity\Subscription;
use App\Entity\User;
use App\Services\CaisseService\StoreClock;
use App\Services\SubscriptionService\SubscriptionLock;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * « Fait » et suivi des droits (lot ABO-3a, 20/11/2026, `docs/boutique.md` § « Fait et suivi », contrat validé par le chef) :
 * - l'ÉQUIPE marque une date planifiée « fait » (note et photo de preuve facultatives) ou « absent » (compté comme utilisé, décision 1),
 *   le jour même ou après ; une séance entière d'un coup, sauf les absents désignés ; corrige un constat posé par erreur (motif, journal ;
 *   tant que la période du droit court, et toujours 7 jours après le constat ; jamais pendant une contestation) ; tranche une contestation
 *   (maintenu, ou droit rendu à planifier) ; lit « À faire aujourd'hui » et les contestations en attente ;
 * - l'ABONNÉ reçoit UN courriel « fait » par droit (jamais un courriel groupé ; il peut les couper), lit ses prestations faites avec la
 *   note et la photo, confirme ou conteste dans les 30 jours (une contestation ouvre une demande d'Assistance si le module est actif ;
 *   jamais de remboursement automatique, décision 6).
 * Deux gestes identiques : 200 sans rien changer ni écrire une seconde fois (idempotence). Chaque geste sur un droit se fait sous le verrou
 * de l'abonnement puis la ligne verrouillée (FOR UPDATE), comme l'agenda. Dates ATOM à l'heure du magasin.
 */
class AboDeliveryService
{
    public const NOTE_MAX = 300;
    public const COMMENT_MIN = 10;
    public const COMMENT_MAX = 1000;
    /** L'abonné confirme ou conteste dans les 30 jours qui suivent le constat */
    public const ACK_DAYS = 30;
    /** Corriger un constat : tant que la période du droit court, et toujours dans les 7 jours qui suivent le constat */
    public const UNDO_GRACE_DAYS = 7;
    /** Décision 9 : la photo de preuve est gardée 12 mois (son empreinte reste) */
    public const PROOF_MONTHS = 12;
    public const DELIVERED_DAYS = 90;
    public const DELIVERED_MAX = 20;
    public const TODAY_MAX = 300;
    public const CONTESTATIONS_MAX = 100;
    public const SESSION_MAX = 500;
    private const MARKED = [BenefitOccurrence::STATUS_DONE, BenefitOccurrence::STATUS_MISSED];
    /** L'avis de l'abonné et la décision remis à zéro : un nouveau constat, ou une correction */
    private const ACK_RESET = 'customer_ack = NULL, customer_ack_comment = NULL, customer_ack_at = NULL, ack_ticket_ref = NULL, ack_resolution = NULL, ack_resolved_at = NULL, ack_resolved_by = NULL';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenant,
        private readonly BenefitService $benefits,
        private readonly StoreClock $clock,
        private readonly AboProofStorage $proofs,
        private readonly LoggerInterface $logger,
        private readonly ?\App\Services\ContentAuditService\ContentAuditRecorder $audit = null,
        private readonly ?\App\Services\SubscriptionService\SubscriptionMailer $mailer = null,
        private readonly ?\App\Support\Services\SupportCustomerService $support = null,
    ) {
    }

    // ---- constats de l'équipe ---------------------------------------------------------------------------------------------------------

    /**
     * Marque « fait » une date planifiée, avec une note et une photo facultatives ; un seul courriel à l'abonné, jamais deux.
     *
     * @return array{occurrence: array<string, mixed>, changed: bool}
     * @throws AboException 404 OCCURRENCE_NOT_FOUND, 409 NOT_YET_DUE | OCCURRENCE_NOT_SCHEDULED | OCCURRENCE_ALREADY_MARKED, 422 PROOF_TOO_LARGE | PROOF_FORMAT
     */
    public function markDone(Subscription $subscription, int $occurrenceId, ?string $note, ?UploadedFile $photo, ?string $by, string $host = '', string $locale = 'fr'): array
    {
        $note = self::clean($note, self::NOTE_MAX);
        $proof = null;
        if ($photo !== null) {
            if (($refusal = AboProofStorage::refusal($photo)) !== null) {
                throw new AboException(422, $refusal, $refusal === 'PROOF_TOO_LARGE' ? 'La photo dépasse 10 Mo.' : 'Photo illisible : JPEG, PNG ou WebP, 12 000 pixels au plus de côté.', 'photo');
            }
            // réencodée AVANT le verrou (le travail long hors du verrou) ; retirée si elle ne sert pas
            $proof = $this->proofs->store($photo) ?? throw new AboException(422, 'PROOF_FORMAT', 'Photo illisible : JPEG, PNG ou WebP.', 'photo');
        }
        $used = false;
        try {
            $changed = $this->locked($subscription, function () use ($subscription, $occurrenceId, $note, $proof, $by, &$used) {
                return $this->conn()->transactional(function (Connection $conn) use ($subscription, $occurrenceId, $note, $proof, $by, &$used) {
                    $o = $this->row($conn, $subscription, $occurrenceId);
                    if ($o['status'] === BenefitOccurrence::STATUS_DONE && $o['scheduled_at'] !== null) {
                        return false; // déjà fait : rien de changé, aucun second courriel (la photo envoyée de nouveau n'est pas gardée)
                    }
                    $this->assertMarkable($o);
                    $now = self::utc(new \DateTimeImmutable());
                    // relecture neuve : un nouveau constat repart d'un avis vierge (un droit rendu puis replanifié se confirme ou se conteste de nouveau)
                    $conn->executeStatement("UPDATE benefit_occurrence SET status = 'done', done_at = ?, done_by = ?, note = ?, proof_path = ?, proof_sha256 = ?, proof_mime = ?, proof_at = ?,
                        " . self::ACK_RESET . ' WHERE id = ?',
                        [$now, $by, $note, $proof['path'] ?? null, $proof['sha256'] ?? null, $proof['mime'] ?? null, $proof !== null ? $now : null, $occurrenceId]);
                    $used = $proof !== null;

                    return true;
                });
            });
        } finally {
            if ($proof !== null && !$used) {
                $this->proofs->remove($proof['path']);
            }
        }
        if ($changed) {
            $benefitId = $this->benefitOf($occurrenceId);
            $this->event($subscription, 'done', $benefitId, $note, $by, $occurrenceId);
            $this->journal($subscription, 'occurrence_done', ['occurrenceId' => $occurrenceId, 'proof' => $proof !== null]);
            $this->notifyDone($subscription, $occurrenceId, $host, $locale);
        }

        return ['occurrence' => $this->view($occurrenceId, true, $locale), 'changed' => $changed];
    }

    /**
     * Marque « absent » une date planifiée : le droit compte comme utilisé (décision 1), sans courriel.
     *
     * @return array{occurrence: array<string, mixed>, changed: bool}
     * @throws AboException 404 OCCURRENCE_NOT_FOUND, 409 NOT_YET_DUE | OCCURRENCE_NOT_SCHEDULED | OCCURRENCE_ALREADY_MARKED
     */
    public function markMissed(Subscription $subscription, int $occurrenceId, ?string $note, ?string $by, string $locale = 'fr'): array
    {
        $note = self::clean($note, self::NOTE_MAX);
        $changed = $this->locked($subscription, fn () => $this->conn()->transactional(function (Connection $conn) use ($subscription, $occurrenceId, $note, $by) {
            $o = $this->row($conn, $subscription, $occurrenceId);
            if ($o['status'] === BenefitOccurrence::STATUS_MISSED) {
                return false;
            }
            $this->assertMarkable($o);
            $conn->executeStatement("UPDATE benefit_occurrence SET status = 'missed', done_at = ?, done_by = ?, note = ?, " . self::ACK_RESET . ' WHERE id = ?', [self::utc(new \DateTimeImmutable()), $by, $note, $occurrenceId]);

            return true;
        }));
        if ($changed) {
            $this->event($subscription, 'missed', $this->benefitOf($occurrenceId), $note, $by, $occurrenceId);
            $this->journal($subscription, 'occurrence_missed', ['occurrenceId' => $occurrenceId]);
        }

        return ['occurrence' => $this->view($occurrenceId, true, $locale), 'changed' => $changed];
    }

    /**
     * Une séance entière : « fait » pour chaque abonné inscrit, sauf les absents désignés (numéros d'abonnement) ; un courriel par abonné.
     * Un droit déjà marqué reste tel quel ; un abonnement tenu par un autre traitement est rendu dans `failed`, sans arrêter les autres.
     *
     * @param mixed $absent liste de numéros d'abonnement
     * @return array{slotId: int, done: int, missed: int, unchanged: int, failed: list<array{occurrenceId: int, subscriptionId: int, code: string}>}
     * @throws AboException 404 SLOT_NOT_FOUND, 409 SLOT_CANCELLED | NOT_YET_DUE, 422 ABSENT_INVALID | ABSENT_NOT_ON_SLOT
     */
    public function markSessionDone(int $slotId, mixed $absent, ?string $note, ?string $by, string $host = '', string $locale = 'fr'): array
    {
        $absent ??= [];
        if (!is_array($absent) || count($absent) > self::SESSION_MAX || array_filter($absent, fn ($v) => !is_int($v) && !(is_string($v) && ctype_digit($v))) !== []) {
            throw new AboException(422, 'ABSENT_INVALID', 'Les absents sont une liste de numéros d\'abonnement.', 'absentSubscriptionIds');
        }
        $absent = array_values(array_unique(array_map('intval', $absent)));
        $conn = $this->conn();
        $slot = $conn->fetchAssociative('SELECT id, status, starts_at FROM benefit_slot WHERE id = ?', [$slotId]) ?: throw new AboException(404, 'SLOT_NOT_FOUND', 'Créneau introuvable.');
        if ($slot['status'] !== BenefitSlot::STATUS_OPEN) {
            throw new AboException(409, 'SLOT_CANCELLED', 'Ce créneau est annulé.');
        }
        $this->assertDue(new \DateTimeImmutable((string) $slot['starts_at'], new \DateTimeZone('UTC')));
        $rows = $conn->fetchAllAssociative("SELECT id, subscription_id FROM benefit_occurrence WHERE slot_id = ? AND status IN ('scheduled', 'done', 'missed') ORDER BY id LIMIT " . self::SESSION_MAX, [$slotId]);
        $onSlot = array_map(fn (array $r) => (int) $r['subscription_id'], $rows);
        if (($stranger = array_diff($absent, $onSlot)) !== []) {
            throw new AboException(422, 'ABSENT_NOT_ON_SLOT', sprintf('Abonnement(s) %s : pas inscrit(s) à cette séance.', implode(', ', $stranger)), 'absentSubscriptionIds');
        }
        $out = ['slotId' => $slotId, 'done' => 0, 'missed' => 0, 'unchanged' => 0, 'failed' => []];
        $em = $this->emProvider->getEntityManager();
        foreach ($rows as $r) {
            $subscription = $em->getRepository(Subscription::class)->find((int) $r['subscription_id']);
            if ($subscription === null) {
                continue;
            }
            $isAbsent = in_array((int) $r['subscription_id'], $absent, true);
            try {
                $res = $isAbsent ? $this->markMissed($subscription, (int) $r['id'], $note, $by, $locale) : $this->markDone($subscription, (int) $r['id'], $note, null, $by, $host, $locale);
                $res['changed'] ? ++$out[$isAbsent ? 'missed' : 'done'] : ++$out['unchanged'];
            } catch (AboException $e) {
                $out['failed'][] = ['occurrenceId' => (int) $r['id'], 'subscriptionId' => (int) $r['subscription_id'], 'code' => $e->errorCode];
            } catch (\App\Services\SubscriptionService\SubscriptionLockTimeout) {
                $out['failed'][] = ['occurrenceId' => (int) $r['id'], 'subscriptionId' => (int) $r['subscription_id'], 'code' => 'SUBSCRIPTION_BUSY'];
            }
        }

        return $out;
    }

    /**
     * Corrige un « fait » ou un « absent » posé par erreur (l'administrateur, motif obligatoire, au journal) : la date redevient planifiée
     * et le droit revient au solde ; la photo est effacée. Refusé pendant une contestation, et une fois la période du droit close (au-delà
     * de 7 jours après le constat).
     *
     * @return array<string, mixed> le droit
     * @throws AboException 404 OCCURRENCE_NOT_FOUND, 409 OCCURRENCE_NOT_MARKED | CONTESTATION_PENDING | UNDO_PERIOD_CLOSED | OCCURRENCE_NOT_SCHEDULED, 422 REASON_REQUIRED
     */
    public function undo(Subscription $subscription, int $occurrenceId, ?string $reason, ?string $by, string $locale = 'fr'): array
    {
        $reason = self::clean($reason, self::NOTE_MAX);
        if ($reason === null) {
            throw new AboException(422, 'REASON_REQUIRED', 'Donnez le motif de la correction.', 'reason');
        }
        $before = $this->locked($subscription, fn () => $this->conn()->transactional(function (Connection $conn) use ($subscription, $occurrenceId) {
            $o = $this->row($conn, $subscription, $occurrenceId);
            if (!in_array($o['status'], self::MARKED, true)) {
                throw new AboException(409, 'OCCURRENCE_NOT_MARKED', 'Ce droit n\'est ni fait ni absent : rien à corriger.');
            }
            if ($o['scheduled_at'] === null) {
                throw new AboException(409, 'OCCURRENCE_NOT_SCHEDULED', 'Une utilisation sans date s\'annule par « Annuler l\'utilisation », dans les 24 heures.');
            }
            if ($o['customer_ack'] === BenefitOccurrence::ACK_CONTESTED && $o['ack_resolution'] === null) {
                throw new AboException(409, 'CONTESTATION_PENDING', 'L\'abonné conteste ce constat : tranchez la contestation (maintenu ou droit rendu).');
            }
            $now = new \DateTimeImmutable();
            $periodEnd = new \DateTimeImmutable((string) ($o['period_end'] ?? $o['valid_until']), new \DateTimeZone('UTC'));
            $markedAt = $o['done_at'] !== null ? new \DateTimeImmutable((string) $o['done_at'], new \DateTimeZone('UTC')) : $now;
            if ($periodEnd <= $now && $markedAt < $now->modify('-' . self::UNDO_GRACE_DAYS . ' days')) {
                throw new AboException(409, 'UNDO_PERIOD_CLOSED', sprintf('La période de ce droit est close : un constat se corrige tant que la période court, ou dans les %d jours.', self::UNDO_GRACE_DAYS));
            }
            $conn->executeStatement("UPDATE benefit_occurrence SET status = 'scheduled', done_at = NULL, done_by = NULL, note = NULL, proof_path = NULL, proof_sha256 = NULL, proof_mime = NULL, proof_at = NULL,
                " . self::ACK_RESET . ' WHERE id = ?', [$occurrenceId]);
            // relecture neuve : le créneau annulé entre-temps ne reprend pas la date ; le droit redevient à planifier, sans perte
            if ($o['slot_id'] !== null && $conn->fetchOne('SELECT status FROM benefit_slot WHERE id = ? FOR UPDATE', [$o['slot_id']]) !== BenefitSlot::STATUS_OPEN) {
                $conn->executeStatement("UPDATE benefit_occurrence SET status = 'available', slot_id = NULL, scheduled_at = NULL, scheduled_end = NULL, scheduled_by = NULL, scheduled_source = NULL,
                    valid_until = CASE WHEN valid_until <= ? THEN ? ELSE valid_until END WHERE id = ?", [self::utc(new \DateTimeImmutable()), AboAgendaService::floor($conn, (int) $subscription->getId()), $occurrenceId]);
            }

            return $o;
        }));
        $this->proofs->remove($before['proof_path']);
        $this->event($subscription, $before['status'] === BenefitOccurrence::STATUS_DONE ? 'done_undone' : 'missed_undone', (int) $before['benefit_id'], $reason, $by, $occurrenceId);
        $this->journal($subscription, 'occurrence_undo', ['occurrenceId' => $occurrenceId, 'was' => $before['status'], 'reason' => $reason]);

        return $this->view($occurrenceId, true, $locale);
    }

    /**
     * Tranche une contestation : `kept` (le constat est maintenu) ou `returned` (le droit redevient à planifier, sans perte). Jamais de
     * remboursement (décision 6).
     *
     * @return array<string, mixed> le droit
     * @throws AboException 404 OCCURRENCE_NOT_FOUND, 409 NO_PENDING_CONTESTATION, 422 RESOLUTION_INVALID
     */
    public function resolve(Subscription $subscription, int $occurrenceId, mixed $decision, ?string $note, ?string $by, string $locale = 'fr'): array
    {
        if (!in_array($decision, [BenefitOccurrence::RESOLUTION_KEPT, BenefitOccurrence::RESOLUTION_RETURNED], true)) {
            throw new AboException(422, 'RESOLUTION_INVALID', 'Décision attendue : kept (constat maintenu) ou returned (droit rendu).', 'decision');
        }
        $note = self::clean($note, self::NOTE_MAX);
        $before = $this->locked($subscription, fn () => $this->conn()->transactional(function (Connection $conn) use ($subscription, $occurrenceId, $decision, $by) {
            $o = $this->row($conn, $subscription, $occurrenceId);
            if ($o['customer_ack'] !== BenefitOccurrence::ACK_CONTESTED || $o['ack_resolution'] !== null || !in_array($o['status'], self::MARKED, true)) {
                throw new AboException(409, 'NO_PENDING_CONTESTATION', 'Aucune contestation en attente sur ce droit.');
            }
            $now = self::utc(new \DateTimeImmutable());
            if ($decision === BenefitOccurrence::RESOLUTION_KEPT) {
                $conn->executeStatement('UPDATE benefit_occurrence SET ack_resolution = ?, ack_resolved_at = ?, ack_resolved_by = ? WHERE id = ?', [$decision, $now, $by, $occurrenceId]);
            } else {
                // le droit redevient à planifier (comme une séance annulée par la boutique), valable au moins jusqu'à la fin de la période en cours
                $conn->executeStatement("UPDATE benefit_occurrence SET status = 'available', slot_id = NULL, scheduled_at = NULL, scheduled_end = NULL, scheduled_by = NULL, scheduled_source = NULL,
                    done_at = NULL, done_by = NULL, note = NULL, proof_path = NULL, proof_sha256 = NULL, proof_mime = NULL, proof_at = NULL, done_notified_at = NULL,
                    valid_until = CASE WHEN valid_until <= ? THEN ? ELSE valid_until END, ack_resolution = ?, ack_resolved_at = ?, ack_resolved_by = ? WHERE id = ?",
                    [$now, AboAgendaService::floor($conn, (int) $subscription->getId()), $decision, $now, $by, $occurrenceId]);
            }

            return $o;
        }));
        if ($decision === BenefitOccurrence::RESOLUTION_RETURNED) {
            $this->proofs->remove($before['proof_path']);
        }
        $this->event($subscription, $decision === BenefitOccurrence::RESOLUTION_KEPT ? 'contest_kept' : 'contest_returned', (int) $before['benefit_id'], $note, $by, $occurrenceId);
        $this->journal($subscription, 'contest_' . $decision, ['occurrenceId' => $occurrenceId]);

        return $this->view($occurrenceId, true, $locale);
    }

    // ---- l'abonné --------------------------------------------------------------------------------------------------------------------

    /**
     * L'avis de l'abonné sur un « fait » ou un « absent » : `confirmed` ou `contested` (commentaire de 10 à 1000 caractères), dans les
     * 30 jours ; le même avis une seconde fois : 200 sans rien changer ; un autre avis ensuite : 409. Une contestation ouvre une demande
     * d'Assistance quand le module est actif (son numéro est gardé), jamais de remboursement.
     *
     * @return array{occurrenceId: int, customerAck: string, ticket: ?string, changed: bool}
     * @throws AboException 404 OCCURRENCE_NOT_FOUND, 409 ACK_ALREADY_GIVEN | ACK_TOO_LATE, 422 DECISION_INVALID | COMMENT_REQUIRED | COMMENT_LENGTH
     */
    public function acknowledge(Subscription $subscription, User $customer, int $occurrenceId, mixed $decision, mixed $comment, string $locale = 'fr'): array
    {
        if (!in_array($decision, [BenefitOccurrence::ACK_CONFIRMED, BenefitOccurrence::ACK_CONTESTED], true)) {
            throw new AboException(422, 'DECISION_INVALID', 'Avis attendu : confirmed ou contested.', 'decision');
        }
        $text = is_string($comment) ? trim(strip_tags($comment)) : '';
        if ($decision === BenefitOccurrence::ACK_CONTESTED && $text === '') {
            throw new AboException(422, 'COMMENT_REQUIRED', 'Dites en quelques mots ce qui ne va pas.', 'comment');
        }
        if ($text !== '' && (mb_strlen($text) > self::COMMENT_MAX || ($decision === BenefitOccurrence::ACK_CONTESTED && mb_strlen($text) < self::COMMENT_MIN))) {
            throw new AboException(422, 'COMMENT_LENGTH', sprintf('De %d à %d caractères.', self::COMMENT_MIN, self::COMMENT_MAX), 'comment');
        }
        [$changed, $o] = $this->locked($subscription, fn () => $this->conn()->transactional(function (Connection $conn) use ($subscription, $occurrenceId, $decision, $text) {
            $o = $conn->fetchAssociative('SELECT * FROM benefit_occurrence WHERE id = ? AND subscription_id = ? FOR UPDATE', [$occurrenceId, $subscription->getId()]);
            if ($o === false || !in_array($o['status'], self::MARKED, true) || $o['scheduled_at'] === null) {
                throw new AboException(404, 'OCCURRENCE_NOT_FOUND', 'Prestation introuvable.', 'occurrenceId');
            }
            if ($o['customer_ack'] !== null) {
                if ($o['customer_ack'] === $decision) {
                    return [false, $o];
                }
                throw new AboException(409, 'ACK_ALREADY_GIVEN', 'Vous avez déjà donné votre avis sur cette prestation.');
            }
            if ($o['done_at'] !== null && new \DateTimeImmutable((string) $o['done_at'], new \DateTimeZone('UTC')) < (new \DateTimeImmutable())->modify('-' . self::ACK_DAYS . ' days')) {
                throw new AboException(409, 'ACK_TOO_LATE', sprintf('L\'avis se donne dans les %d jours.', self::ACK_DAYS));
            }
            $conn->executeStatement('UPDATE benefit_occurrence SET customer_ack = ?, customer_ack_comment = ?, customer_ack_at = ? WHERE id = ?',
                [$decision, $text !== '' ? $text : null, self::utc(new \DateTimeImmutable()), $occurrenceId]);

            return [true, $o];
        }));
        $ticket = $o['ack_ticket_ref'] ?? null;
        if ($changed) {
            $this->event($subscription, $decision, (int) $o['benefit_id'], $text !== '' ? mb_substr($text, 0, self::NOTE_MAX) : null, 'client', $occurrenceId);
            $this->journal($subscription, 'occurrence_' . $decision, ['occurrenceId' => $occurrenceId]);
            if ($decision === BenefitOccurrence::ACK_CONTESTED) {
                $ticket = $this->openTicket($customer, $subscription, $o, $text, $locale);
            }
        }

        return ['occurrenceId' => $occurrenceId, 'customerAck' => $decision, 'ticket' => $ticket, 'changed' => $changed];
    }

    /** L'abonné coupe ou rallume le courriel « prestation faite » @return array{doneEmails: bool} */
    public function setDoneEmails(Subscription $subscription, mixed $on): array
    {
        if (!is_bool($on)) {
            throw new AboException(422, 'DONE_EMAILS_INVALID', 'Valeur attendue : true ou false.', 'doneEmails');
        }
        $subscription->setDoneEmails($on);
        $this->emProvider->getEntityManager()->flush();

        return ['doneEmails' => $on];
    }

    // ---- lectures ----------------------------------------------------------------------------------------------------------------------

    /**
     * « À faire aujourd'hui » (ou un autre jour du magasin, `AAAA-MM-JJ`) : les séances du jour (même sans inscrit) et leurs inscrits, puis
     * les dates libres. `$withIdentity` faux (assistant) : le numéro d'abonnement seulement, jamais le nom, la note ni l'intervenant.
     *
     * @return array<string, mixed>
     * @throws AboException 422 DATE_INVALID
     */
    public function today(?string $date, bool $withIdentity, string $locale = 'fr'): array
    {
        $tz = $this->clock->timezone();
        $day = $date === null || $date === '' ? (new \DateTimeImmutable('now', $tz))->format('Y-m-d') : $date;
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, $tz);
        if ($parsed === false || $parsed->format('Y-m-d') !== $day) {
            throw new AboException(422, 'DATE_INVALID', 'Date attendue : AAAA-MM-JJ.', 'date');
        }
        [$start, $end] = $this->clock->range($day, $day);
        $conn = $this->conn();
        $bounds = [self::utc($start), self::utc($end)];
        $slots = $conn->fetchAllAssociative("SELECT id, benefit_id, starts_at, ends_at, capacity, location, staff_user_id FROM benefit_slot WHERE status = 'open' AND starts_at >= ? AND starts_at < ? ORDER BY starts_at, id LIMIT " . self::TODAY_MAX, $bounds);
        $rows = $conn->fetchAllAssociative("SELECT o.id, o.subscription_id, o.benefit_id, o.slot_id, o.scheduled_at, o.scheduled_end, o.status, o.done_at, o.note, o.proof_path, o.customer_ack, o.ack_resolution
            FROM benefit_occurrence o WHERE o.scheduled_at >= ? AND o.scheduled_at < ? AND o.status IN ('scheduled', 'done', 'missed', 'late_cancelled') ORDER BY o.scheduled_at, o.id LIMIT " . self::TODAY_MAX, $bounds);
        $sessions = [];
        foreach ($slots as $s) {
            $sessions[(int) $s['id']] = ['slotId' => (int) $s['id'], 'benefitId' => (int) $s['benefit_id'], 'name' => $this->benefitName((int) $s['benefit_id'], $locale),
                'startsAt' => $this->at(self::fromUtc($s['starts_at'])), 'endsAt' => $this->at(self::fromUtc($s['ends_at'])), 'location' => $s['location'], 'capacity' => (int) $s['capacity']]
                + ($withIdentity ? ['staffUserId' => $s['staff_user_id'] !== null ? (int) $s['staff_user_id'] : null] : [])
                + ['booked' => 0, 'done' => 0, 'missed' => 0, 'lateCancelled' => 0, 'toMark' => 0, 'appointments' => []];
        }
        $free = [];
        $counts = ['toMark' => 0, 'done' => 0, 'missed' => 0];
        $customers = $withIdentity ? $this->customers(array_map(fn (array $r) => (int) $r['subscription_id'], $rows)) : [];
        foreach ($rows as $r) {
            $item = ['occurrenceId' => (int) $r['id'], 'subscriptionId' => (int) $r['subscription_id'], 'benefitId' => (int) $r['benefit_id'], 'name' => $this->benefitName((int) $r['benefit_id'], $locale),
                'status' => (string) $r['status'], 'startsAt' => $this->at(self::fromUtc($r['scheduled_at'])), 'endsAt' => $this->at(self::fromUtc($r['scheduled_end'])),
                'doneAt' => $this->at(self::fromUtc($r['done_at'])), 'hasProof' => $r['proof_path'] !== null, ($withIdentity ? 'customerAck' : 'ack') => $r['customer_ack'], 'contestationPending' => $r['customer_ack'] === BenefitOccurrence::ACK_CONTESTED && $r['ack_resolution'] === null]
                + ($withIdentity ? ['note' => $r['note'], 'customer' => $customers[(int) $r['subscription_id']] ?? null] : []);
            $key = match ((string) $r['status']) { 'scheduled' => 'toMark', 'done' => 'done', 'missed' => 'missed', default => null };
            if ($key !== null) {
                ++$counts[$key];
            }
            $slotId = $r['slot_id'] !== null ? (int) $r['slot_id'] : null;
            if ($slotId !== null && isset($sessions[$slotId])) {
                $sessions[$slotId]['appointments'][] = $item;
                $r['status'] === 'late_cancelled' ? ++$sessions[$slotId]['lateCancelled'] : ++$sessions[$slotId]['booked'];
                if ($key !== null) {
                    ++$sessions[$slotId][$key];
                }
            } else {
                $free[] = $item; // date libre (`admin_after`), ou créneau annulé depuis (rien n'y reste planifié)
            }
        }

        return ['date' => $day, 'timezone' => $tz->getName(), 'counts' => $counts, 'sessions' => array_values($sessions), 'appointments' => $free];
    }

    /**
     * Les contestations en attente (les plus anciennes d'abord). `$withIdentity` faux (assistant) : numéro d'abonnement, commentaire avec
     * courriels et téléphones masqués, jamais le nom.
     *
     * @return list<array<string, mixed>>
     */
    public function contestations(bool $withIdentity, string $locale = 'fr'): array
    {
        $rows = $this->conn()->fetchAllAssociative("SELECT id, subscription_id, benefit_id, status, scheduled_at, done_at, done_by, customer_ack_comment, customer_ack_at, ack_ticket_ref FROM benefit_occurrence
            WHERE customer_ack = 'contested' AND ack_resolution IS NULL ORDER BY customer_ack_at, id LIMIT " . self::CONTESTATIONS_MAX);
        $customers = $withIdentity ? $this->customers(array_map(fn (array $r) => (int) $r['subscription_id'], $rows)) : [];

        return array_map(fn (array $r) => ['occurrenceId' => (int) $r['id'], 'subscriptionId' => (int) $r['subscription_id'], 'benefitId' => (int) $r['benefit_id'],
            'name' => $this->benefitName((int) $r['benefit_id'], $locale), 'status' => (string) $r['status'], 'startsAt' => $this->at(self::fromUtc($r['scheduled_at'])),
            'doneAt' => $this->at(self::fromUtc($r['done_at'])), 'contestedAt' => $this->at(self::fromUtc($r['customer_ack_at'])), 'ticket' => $r['ack_ticket_ref'],
            'comment' => $withIdentity ? $r['customer_ack_comment'] : self::maskContacts((string) $r['customer_ack_comment'])]
            + ($withIdentity ? ['doneBy' => $r['done_by'], 'customer' => $customers[(int) $r['subscription_id']] ?? null] : []), $rows);
    }

    /**
     * Les prestations faites ou manquées d'un abonnement (90 jours, 20 au plus, les plus récentes d'abord), avec la note, la présence d'une
     * photo et l'avis. Côté abonné : jusqu'à quand il peut donner son avis ; jamais « par qui » ni la décision interne.
     *
     * @return list<array<string, mixed>>
     */
    public function delivered(Subscription $subscription, bool $forTeam, string $locale = 'fr'): array
    {
        $rows = $this->conn()->fetchAllAssociative("SELECT id, benefit_id, status, scheduled_at, done_at, done_by, note, proof_path, customer_ack, customer_ack_comment, customer_ack_at, ack_ticket_ref, ack_resolution
            FROM benefit_occurrence WHERE subscription_id = ? AND status IN ('done', 'missed') AND scheduled_at IS NOT NULL AND done_at >= ? ORDER BY done_at DESC, id DESC LIMIT " . self::DELIVERED_MAX,
            [$subscription->getId(), self::utc((new \DateTimeImmutable())->modify('-' . self::DELIVERED_DAYS . ' days'))]);

        return array_map(function (array $r) use ($forTeam, $locale) {
            $doneAt = self::fromUtc($r['done_at']);
            $view = ['occurrenceId' => (int) $r['id'], 'benefitId' => (int) $r['benefit_id'], 'name' => $this->benefitName((int) $r['benefit_id'], $locale), 'status' => (string) $r['status'],
                'startsAt' => $this->at(self::fromUtc($r['scheduled_at'])), 'doneAt' => $this->at($doneAt), 'note' => $r['note'], 'hasProof' => $r['proof_path'] !== null,
                'customerAck' => $r['customer_ack'], 'ackUntil' => $r['customer_ack'] === null && $doneAt !== null ? $this->at($doneAt->modify('+' . self::ACK_DAYS . ' days')) : null];

            return $view + ($forTeam ? ['doneBy' => $r['done_by'], 'ackComment' => $r['customer_ack_comment'], 'ackAt' => $this->at(self::fromUtc($r['customer_ack_at'])),
                'ticket' => $r['ack_ticket_ref'], 'resolution' => $r['ack_resolution']] : []);
        }, $rows);
    }

    /**
     * La photo de preuve d'un droit de cet abonnement.
     *
     * @return array{path: string, mime: string, sha256: ?string}
     * @throws AboException 404 PROOF_NOT_FOUND
     */
    public function proofFile(Subscription $subscription, int $occurrenceId): array
    {
        $r = $this->conn()->fetchAssociative('SELECT proof_path, proof_mime, proof_sha256 FROM benefit_occurrence WHERE id = ? AND subscription_id = ?', [$occurrenceId, $subscription->getId()]);
        $path = $r !== false ? $this->proofs->file($r['proof_path']) : null;
        if ($path === null) {
            throw new AboException(404, 'PROOF_NOT_FOUND', 'Aucune photo pour cette prestation.');
        }

        return ['path' => $path, 'mime' => (string) ($r['proof_mime'] ?? 'image/jpeg'), 'sha256' => $r['proof_sha256']];
    }

    /** Décision 9 : efface les photos de plus de 12 mois (l'empreinte et la date restent) ; passage quotidien @return int photos effacées */
    public function purgeProofs(?\DateTimeImmutable $now = null): int
    {
        $limit = self::utc(($now ?? new \DateTimeImmutable())->modify('-' . self::PROOF_MONTHS . ' months'));
        $conn = $this->conn();
        $n = 0;
        foreach ($conn->fetchAllAssociative('SELECT id, proof_path FROM benefit_occurrence WHERE proof_path IS NOT NULL AND proof_at < ? ORDER BY id LIMIT 1000', [$limit]) as $r) {
            if ($conn->executeStatement('UPDATE benefit_occurrence SET proof_path = NULL WHERE id = ? AND proof_path = ?', [$r['id'], $r['proof_path']]) === 1) {
                $this->proofs->remove((string) $r['proof_path']);
                ++$n;
            }
        }

        return $n;
    }

    /** Un droit pour l'équipe (après un geste) @return array<string, mixed> */
    public function view(int $occurrenceId, bool $forTeam, string $locale = 'fr'): array
    {
        $r = $this->conn()->fetchAssociative('SELECT * FROM benefit_occurrence WHERE id = ?', [$occurrenceId]) ?: throw new AboException(404, 'OCCURRENCE_NOT_FOUND', 'Droit introuvable.');
        $view = ['occurrenceId' => (int) $r['id'], 'subscriptionId' => (int) $r['subscription_id'], 'benefitId' => (int) $r['benefit_id'], 'name' => $this->benefitName((int) $r['benefit_id'], $locale),
            'status' => (string) $r['status'], 'slotId' => $r['slot_id'] !== null ? (int) $r['slot_id'] : null, 'startsAt' => $this->at(self::fromUtc($r['scheduled_at'])),
            'endsAt' => $this->at(self::fromUtc($r['scheduled_end'])), 'doneAt' => $this->at(self::fromUtc($r['done_at'])), 'note' => $r['note'], 'hasProof' => $r['proof_path'] !== null,
            'customerAck' => $r['customer_ack']];

        return $view + ($forTeam ? ['doneBy' => $r['done_by'], 'ackComment' => $r['customer_ack_comment'], 'ticket' => $r['ack_ticket_ref'], 'resolution' => $r['ack_resolution']] : []);
    }

    /**
     * Pour l'aperçu de l'assistant : la date planifiée à marquer, vérifiée sans rien écrire (relue sous le verrou à la confirmation).
     *
     * @return array<string, mixed> le droit, sans « par qui » ni note
     * @throws AboException 404 OCCURRENCE_NOT_FOUND, 409 NOT_YET_DUE | OCCURRENCE_NOT_SCHEDULED | OCCURRENCE_ALREADY_MARKED
     */
    public function markable(Subscription $subscription, int $occurrenceId): array
    {
        $o = $this->conn()->fetchAssociative('SELECT * FROM benefit_occurrence WHERE id = ? AND subscription_id = ?', [$occurrenceId, $subscription->getId()])
            ?: throw new AboException(404, 'OCCURRENCE_NOT_FOUND', 'Droit introuvable.', 'occurrenceId');
        $this->assertMarkable($o);

        return array_diff_key($this->view($occurrenceId, false), ['note' => 1]);
    }

    /**
     * Pour l'aperçu de l'assistant : une séance à marquer faite, et ses absents vérifiés, sans rien écrire.
     *
     * @return array{name: string, startsAt: ?string, toMark: int, absent: list<int>}
     * @throws AboException 404 SLOT_NOT_FOUND, 409 SLOT_CANCELLED | NOT_YET_DUE | NOTHING_TO_MARK, 422 ABSENT_INVALID | ABSENT_NOT_ON_SLOT
     */
    public function sessionPreview(int $slotId, mixed $absent): array
    {
        $absent ??= [];
        if (!is_array($absent) || count($absent) > self::SESSION_MAX || array_filter($absent, fn ($v) => !is_int($v) && !(is_string($v) && ctype_digit($v))) !== []) {
            throw new AboException(422, 'ABSENT_INVALID', 'Les absents sont une liste de numéros d\'abonnement.', 'absentSubscriptionIds');
        }
        $absent = array_values(array_unique(array_map('intval', $absent)));
        $conn = $this->conn();
        $slot = $conn->fetchAssociative('SELECT id, benefit_id, status, starts_at FROM benefit_slot WHERE id = ?', [$slotId]) ?: throw new AboException(404, 'SLOT_NOT_FOUND', 'Créneau introuvable.', 'slotId');
        if ($slot['status'] !== BenefitSlot::STATUS_OPEN) {
            throw new AboException(409, 'SLOT_CANCELLED', 'Ce créneau est annulé.');
        }
        $start = new \DateTimeImmutable((string) $slot['starts_at'], new \DateTimeZone('UTC'));
        $this->assertDue($start);
        $onSlot = array_map('intval', $conn->fetchFirstColumn("SELECT subscription_id FROM benefit_occurrence WHERE slot_id = ? AND status = 'scheduled'", [$slotId]));
        if ($onSlot === []) {
            throw new AboException(409, 'NOTHING_TO_MARK', 'Aucun inscrit à marquer sur cette séance.');
        }
        if (($stranger = array_diff($absent, $onSlot)) !== []) {
            throw new AboException(422, 'ABSENT_NOT_ON_SLOT', sprintf('Abonnement(s) %s : pas inscrit(s) à cette séance, ou déjà marqué(s).', implode(', ', $stranger)), 'absentSubscriptionIds');
        }

        return ['name' => $this->benefitName((int) $slot['benefit_id'], 'fr'), 'startsAt' => $this->at($start), 'toMark' => count($onSlot), 'absent' => $absent];
    }

    /** Courriels et numéros de téléphone d'un texte saisi par un abonné, masqués avant l'envoi au modèle */
    public static function maskContacts(string $text): string
    {
        $text = (string) preg_replace('/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.\p{L}{2,}/u', '[courriel]', $text);

        return (string) preg_replace('/(?<!\d)(?:\+?\d[\s.\-()]{0,2}){7,}(?!\d)/u', '[téléphone]', $text);
    }

    // ---- interne -----------------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> la ligne verrouillée @throws AboException 404 OCCURRENCE_NOT_FOUND */
    private function row(Connection $conn, Subscription $subscription, int $occurrenceId): array
    {
        $o = $conn->fetchAssociative('SELECT * FROM benefit_occurrence WHERE id = ? AND subscription_id = ? FOR UPDATE', [$occurrenceId, $subscription->getId()]);

        return $o !== false ? $o : throw new AboException(404, 'OCCURRENCE_NOT_FOUND', 'Droit introuvable.', 'occurrenceId');
    }

    /** @param array<string, mixed> $o @throws AboException 409 OCCURRENCE_ALREADY_MARKED | OCCURRENCE_NOT_SCHEDULED | NOT_YET_DUE */
    private function assertMarkable(array $o): void
    {
        // relecture neuve : une utilisation sans date (ABO-1) n'est jamais un constat, même « faite »
        if ($o['scheduled_at'] === null) {
            throw new AboException(409, 'OCCURRENCE_NOT_SCHEDULED', 'Seule une date planifiée se marque faite ou absente.');
        }
        if (in_array($o['status'], self::MARKED, true)) {
            throw new AboException(409, 'OCCURRENCE_ALREADY_MARKED', 'Ce droit est déjà marqué : corrigez d\'abord le constat.');
        }
        if ($o['status'] !== BenefitOccurrence::STATUS_SCHEDULED) {
            throw new AboException(409, 'OCCURRENCE_NOT_SCHEDULED', 'Seule une date planifiée se marque faite ou absente.');
        }
        $this->assertDue(new \DateTimeImmutable((string) $o['scheduled_at'], new \DateTimeZone('UTC')));
    }

    /** Le jour même (heure du magasin) ou après @throws AboException 409 NOT_YET_DUE */
    private function assertDue(\DateTimeImmutable $startsAt): void
    {
        if ($this->clock->day($startsAt) > $this->clock->day(new \DateTimeImmutable())) {
            throw new AboException(409, 'NOT_YET_DUE', 'Une date à venir se marque le jour même ou après.');
        }
    }

    /** Un seul courriel par droit, réservé par la base (deux gestes simultanés n'en envoient qu'un) ; jamais si l'abonné l'a coupé */
    private function notifyDone(Subscription $subscription, int $occurrenceId, string $host, string $locale): void
    {
        if ($this->mailer === null || !$subscription->wantsDoneEmails()) {
            return;
        }
        $conn = $this->conn();
        if ($conn->executeStatement('UPDATE benefit_occurrence SET done_notified_at = ? WHERE id = ? AND done_notified_at IS NULL', [self::utc(new \DateTimeImmutable()), $occurrenceId]) !== 1) {
            return;
        }
        $r = $conn->fetchAssociative('SELECT benefit_id, scheduled_at, note, proof_path FROM benefit_occurrence WHERE id = ?', [$occurrenceId]);
        if ($r === false) {
            return;
        }
        $en = $locale === 'en';
        $rows = [($en ? 'Service' : 'Prestation') => $this->benefitName((int) $r['benefit_id'], $locale),
            'Date' => (string) self::fromUtc($r['scheduled_at'])?->setTimezone($this->clock->timezone())->format('d/m/Y H:i')];
        if ($r['note'] !== null && $r['note'] !== '') {
            $rows['Note'] = (string) $r['note'];
        }
        if ($r['proof_path'] !== null) {
            $rows[$en ? 'Photo' : 'Photo'] = $en ? 'available in your account' : 'disponible dans votre compte';
        }
        $this->mailer->notify($subscription, 'session_done', $locale, $host, ['rows' => $rows]);
    }

    /** @param array<string, mixed> $o */
    private function openTicket(User $customer, Subscription $subscription, array $o, string $comment, string $locale): ?string
    {
        if ($this->support === null || !$this->support->availability()['available']) {
            return null;
        }
        $name = $this->benefitName((int) $o['benefit_id'], $locale);
        $when = (string) self::fromUtc($o['scheduled_at'])?->setTimezone($this->clock->timezone())->format('d/m/Y');
        try {
            $r = $this->support->create($customer, ['subject' => mb_substr(sprintf('Contestation : %s du %s', $name, $when), 0, 150), 'category' => 'other', 'locale' => $locale,
                'message' => mb_substr($comment . "\n\n" . sprintf('(abonnement n° %d, prestation n° %d)', $subscription->getId(), $o['id']), 0, 5000)], [], 'boutique');
            $ref = (string) ($r['request']['reference'] ?? '');
            if ($ref !== '') {
                $this->conn()->executeStatement('UPDATE benefit_occurrence SET ack_ticket_ref = ? WHERE id = ?', [mb_substr($ref, 0, 20), $o['id']]);
            }

            return $ref !== '' ? $ref : null;
        } catch (\Throwable $e) {
            // plafond de demandes du client ou du site, module coupé entre-temps : la contestation est gardée, visible de l'administrateur
            $this->logger->warning('[Abo] Demande d\'Assistance de la contestation non ouverte : ' . $e::class, ['occurrence' => $o['id']]);

            return null;
        }
    }

    /** @param list<int> $subscriptionIds @return array<int, array{id: int, firstname: ?string, lastname: ?string}> */
    private function customers(array $subscriptionIds): array
    {
        $ids = array_values(array_unique($subscriptionIds));
        if ($ids === []) {
            return [];
        }
        $rows = $this->conn()->executeQuery('SELECT s.id AS sid, u.id, u.firstname, u.lastname FROM subscription s JOIN "user" u ON u.id = s.user_id WHERE s.id IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER])->fetchAllAssociative();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['sid']] = ['id' => (int) $r['id'], 'firstname' => $r['firstname'], 'lastname' => $r['lastname']];
        }

        return $out;
    }

    private function benefitOf(int $occurrenceId): ?int
    {
        $id = $this->conn()->fetchOne('SELECT benefit_id FROM benefit_occurrence WHERE id = ?', [$occurrenceId]);

        return $id !== false ? (int) $id : null;
    }

    /** @var array<string, string> */
    private array $names = [];

    private function benefitName(int $benefitId, string $locale): string
    {
        return $this->names[$benefitId . $locale] ??= (string) $this->benefits->benefits()->find($benefitId)?->getName($locale);
    }

    private function event(Subscription $subscription, string $type, ?int $benefitId, ?string $note, ?string $by, int $occurrenceId): void
    {
        $em = $this->emProvider->getEntityManager();
        $em->persist((new SubscriptionAboEvent($subscription, $type, $benefitId, 1, $note !== null ? mb_substr($note, 0, self::NOTE_MAX) : null, $by))->setOccurrenceId($occurrenceId));
        $em->flush();
    }

    private function journal(Subscription $subscription, string $action, array $details): void
    {
        $this->audit?->record('subscription', (string) $subscription->getId(), $action, null, $details, ['abonnements']);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function locked(Subscription $subscription, callable $fn): mixed
    {
        $em = $this->emProvider->getEntityManager();

        return SubscriptionLock::run($em->getConnection(), SubscriptionLock::key((string) $this->tenant->getTenantCode(), $subscription->getStripeSubscriptionId(), $subscription->getId()), $fn);
    }

    private function conn(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }

    private static function clean(?string $text, int $max): ?string
    {
        $text = trim(strip_tags((string) $text));

        return $text !== '' ? mb_substr($text, 0, $max) : null;
    }

    private static function fromUtc(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : null;
    }

    private static function utc(\DateTimeInterface $at): string
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function at(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : \DateTimeImmutable::createFromInterface($at)->setTimezone($this->clock->timezone())->format(\DATE_ATOM);
    }
}
