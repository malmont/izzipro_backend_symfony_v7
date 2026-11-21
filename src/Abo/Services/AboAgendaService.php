<?php

namespace App\Abo\Services;

use App\Abo\Entity\Benefit;
use App\Abo\Entity\BenefitOccurrence;
use App\Abo\Entity\BenefitSlot;
use App\Abo\Entity\BenefitSlotRule;
use App\Abo\Entity\PlanBenefit;
use App\Abo\Entity\SubscriptionAboEvent;
use App\Entity\Subscription;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Services\CaisseService\StoreClock;
use App\Services\SubscriptionService\SubscriptionLock;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Agenda des prestations (lot ABO-2, 20/11/2026, contrat validé par le chef, `docs/plan-abo-universel.md` § 3 et § 7) :
 * - RÈGLES de créneaux à l'heure LOCALE du magasin ; CRÉNEAUX datés nés 8 semaines devant, convertis en UTC JOUR PAR JOUR (heure d'été
 *   juste), idempotents (unicité règle + début) ; une règle modifiée refait les créneaux futurs SANS réservation et garde les autres ;
 * - trois modes de dates (`plan_benefit.scheduling`) : `admin_after` (date libre donnée par l'équipe, sans capacité, Q4), `fixed_slots`
 *   (un créneau ; l'abonné réserve si `slot_choice` = client, sinon l'équipe), `client_at_subscription` (l'abonné choisit à la souscription :
 *   place RETENUE 30 minutes pendant le paiement, Q1, puis comme un créneau au choix du client) ;
 * - CAPACITÉ : verrou du créneau (`pg_advisory_xact_lock`, attente bornée) puis compte des droits planifiés et des places retenues ;
 *   au-delà 409 SLOT_FULL ; un abonnement ne prend jamais deux places du même créneau (409 SLOT_ALREADY_BOOKED) ;
 * - ANNULER : l'abonné avant le délai (24 h par défaut, réglable par prestation de la formule) : le droit redevient à planifier ; après :
 *   `late_cancelled`, compté comme utilisé (décision 1) ; déplacer après le délai : 409 CANCEL_DEADLINE_PASSED ; l'équipe annule et déplace à
 *   tout moment, sans perte, au journal du site (Q3) ; un créneau annulé par la boutique rend ses droits à planifier et prévient chaque
 *   abonné par courriel (Q2, sujet indispensable) ;
 * - un droit qui redevient à planifier après la fin de sa validité (date donnée par l'équipe au-delà, créneau annulé) reste dû : il vaut
 *   jusqu'à la fin de la période en cours (ou 30 jours) ; l'abonné, lui, ne réserve qu'un créneau qui commence avant la fin de validité
 *   de son droit (409 SLOT_AFTER_VALIDITY).
 * Tout geste sur un abonnement passe sous son verrou (SubscriptionLock), puis sous celui du créneau (toujours dans cet ordre).
 */
class AboAgendaService
{
    public const GENERATE_DAYS = 56;
    public const HOLD_MINUTES = 30;
    public const LOCK_TIMEOUT_MS = 3000;
    public const UPCOMING_MAX = 10;
    public const RANGE_MAX_DAYS = 62;
    public const DEFAULT_DURATION = 60;
    /** États d'un abonnement qui peut planifier (l'équipe accepte aussi un impayé en cours de relance) */
    private const CLIENT_STATUSES = [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING];
    private const TEAM_STATUSES = [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING, Subscription::STATUS_PAST_DUE];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenant,
        private readonly BenefitService $benefits,
        private readonly StoreClock $clock,
        private readonly LoggerInterface $logger,
        private readonly ?\App\Services\ContentAuditService\ContentAuditRecorder $audit = null,
        private readonly ?\App\Services\SubscriptionService\SubscriptionMailer $mailer = null,
    ) {
    }

    // ---- règles et créneaux ------------------------------------------------------------------------------------------------------------

    /**
     * Crée ou modifie une règle (EasyAdmin, assistant), puis refait ses créneaux futurs sans réservation.
     *
     * @param array<string, mixed> $data { benefitId, weekdays[], time "HH:MM", durationMinutes?, capacity, location?, staffUserId?, from?, until?, active? }
     * @throws AboException 422 SLOT_RULE_*, 404 BENEFIT_NOT_FOUND
     */
    public function saveRule(?BenefitSlotRule $rule, array $data): BenefitSlotRule
    {
        $em = $this->emProvider->getEntityManager();
        $creating = $rule === null;
        $rule ??= new BenefitSlotRule();
        $this->fillRule($rule, $data, $creating);
        $this->validateRule($rule);
        if ($creating) {
            $em->persist($rule);
        }
        // la règle et ses créneaux d'un seul tenant : un créneau tenu (409 SLOT_BUSY) laisse la règle telle qu'elle était
        $this->conn()->transactional(function () use ($em, $rule) {
            $em->flush();
            $this->regenerate($rule);
        });

        return $rule;
    }

    /** Enregistre une règle déjà remplie (EasyAdmin) : mêmes contrôles, puis ses créneaux futurs refaits @throws AboException */
    public function persistRule(BenefitSlotRule $rule): BenefitSlotRule
    {
        $this->validateRule($rule);
        $em = $this->emProvider->getEntityManager();
        if ($rule->getId() === null) {
            $em->persist($rule);
        }
        $this->conn()->transactional(function () use ($em, $rule) {
            $em->flush();
            $this->regenerate($rule);
        });

        return $rule;
    }

    /** @param array<string, mixed> $data */
    public function fillRule(BenefitSlotRule $rule, array $data, bool $creating): void
    {
        foreach ($data as $key => $value) {
            match ($key) {
                'benefitId' => $rule->setBenefit($this->benefits->benefits()->find((int) $value) ?? throw new AboException(404, 'BENEFIT_NOT_FOUND', 'Prestation introuvable.', 'benefitId')),
                'weekdays' => $rule->setWeekdays(is_array($value) ? array_values(array_filter($value, 'is_scalar')) : throw new AboException(422, 'SLOT_RULE_DAYS_INVALID', 'Jours de la semaine attendus (1 = lundi … 7 = dimanche).', 'weekdays')),
                'time' => $rule->setLocalTime(trim((string) $value)),
                'durationMinutes' => $rule->setDurationMinutes((int) $value),
                'capacity' => $rule->setCapacity((int) $value),
                'location' => $rule->setLocation($value !== null ? (string) $value : null),
                'staffUserId' => $rule->setStaffUserId($value !== null && $value !== '' ? (int) $value : null),
                'from' => $rule->setValidFrom($this->dateOrNull($value, 'from')),
                'until' => $rule->setValidUntil($this->dateOrNull($value, 'until')),
                'active' => $rule->setActive(is_bool($value) ? $value : throw new AboException(422, 'MUST_BE_BOOLEAN', 'active : vrai ou faux.', 'active')),
                default => throw new AboException(422, 'FIELD_UNKNOWN', sprintf('Champ « %s » inconnu.', $key), (string) $key),
            };
        }
        if ($creating && !array_key_exists('durationMinutes', $data)) {
            $rule->setDurationMinutes($rule->getBenefit()?->getDurationMinutes() ?? self::DEFAULT_DURATION);
        }
        if ($creating && !array_key_exists('location', $data) && $rule->getBenefit()?->getLocation() !== null) {
            $rule->setLocation($rule->getBenefit()->getLocation());
        }
    }

    /** @throws AboException 422 SLOT_RULE_* */
    public function validateRule(BenefitSlotRule $rule): void
    {
        $benefit = $rule->getBenefit();
        if ($benefit === null) {
            throw new AboException(422, 'BENEFIT_NOT_FOUND', 'Choisissez la prestation.', 'benefitId');
        }
        if (!in_array($benefit->getKind(), [Benefit::KIND_SESSION, Benefit::KIND_SERVICE], true)) {
            throw new AboException(422, 'SLOT_RULE_KIND_INVALID', 'Seules une séance ou un service se planifient.', 'benefitId');
        }
        $days = $rule->getWeekdays();
        if ($days === [] || array_diff($days, range(1, 7)) !== []) {
            throw new AboException(422, 'SLOT_RULE_DAYS_INVALID', 'Jours de la semaine attendus (1 = lundi … 7 = dimanche).', 'weekdays');
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $rule->getLocalTime())) {
            throw new AboException(422, 'SLOT_RULE_TIME_INVALID', 'Heure attendue (HH:MM, heure du magasin).', 'time');
        }
        if ($rule->getDurationMinutes() < 5 || $rule->getDurationMinutes() > BenefitSlotRule::DURATION_MAX) {
            throw new AboException(422, 'SLOT_RULE_DURATION_INVALID', 'Durée : de 5 minutes à 24 heures.', 'durationMinutes');
        }
        if ($rule->getCapacity() < 1 || $rule->getCapacity() > BenefitSlotRule::CAPACITY_MAX) {
            throw new AboException(422, 'SLOT_RULE_CAPACITY_INVALID', sprintf('Capacité : de 1 à %d places.', BenefitSlotRule::CAPACITY_MAX), 'capacity');
        }
        if ($rule->getValidFrom() !== null && $rule->getValidUntil() !== null && $rule->getValidFrom()->format('Y-m-d') > $rule->getValidUntil()->format('Y-m-d')) {
            throw new AboException(422, 'SLOT_RULE_DATES_INVALID', 'La date de fin précède celle du début.', 'until');
        }
        $this->assertStaff($rule->getStaffUserId());
    }

    /**
     * Refait les créneaux futurs d'une règle. Chaque créneau futur sans réservation ni place retenue est relu SOUS SON VERROU (relecture
     * neuve : jamais supprimé pendant qu'une réservation le prend) : s'il tombe encore un jour et à l'heure de la règle, il est gardé (même
     * numéro) et mis à jour (fin, places, lieu, intervenant) ; sinon il est retiré. Les créneaux manquants sont ensuite créés. Ceux qui ont
     * une réservation restent tels quels. Une règle désactivée ne garde que ses créneaux réservés.
     *
     * @throws AboException 409 SLOT_BUSY (un créneau en cours de réservation : à refaire)
     */
    public function regenerate(BenefitSlotRule $rule, ?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $tz = $this->clock->timezone();
        $this->conn()->transactional(function (Connection $conn) use ($rule, $now, $tz) {
            foreach ($conn->fetchAllAssociative("SELECT id FROM benefit_slot WHERE rule_id = ? AND status = 'open' AND starts_at > ? ORDER BY id", [$rule->getId(), self::utc($now)]) as $row) {
                $id = (int) $row['id'];
                $this->lockSlot($conn, $id);
                $slot = $conn->fetchAssociative('SELECT id, starts_at FROM benefit_slot WHERE id = ?', [$id]);
                if ($slot === false || $conn->fetchOne("SELECT 1 FROM benefit_occurrence WHERE slot_id = ? AND status IN ('scheduled', 'late_cancelled') LIMIT 1", [$id]) !== false
                    || $conn->fetchOne('SELECT 1 FROM benefit_slot_hold WHERE slot_id = ? LIMIT 1', [$id]) !== false) {
                    continue; // réservé (ou parti) : jamais touché
                }
                $start = new \DateTimeImmutable((string) $slot['starts_at'], new \DateTimeZone('UTC'));
                if ($this->matches($rule, $start->setTimezone($tz))) {
                    $conn->executeStatement('UPDATE benefit_slot SET ends_at = ?, capacity = ?, location = ?, staff_user_id = ? WHERE id = ?',
                        [self::utc($start->modify('+' . $rule->getDurationMinutes() . ' minutes')), $rule->getCapacity(), $rule->getLocation(), $rule->getStaffUserId(), $id]);
                } else {
                    $conn->executeStatement('DELETE FROM benefit_slot WHERE id = ?', [$id]);
                }
            }
        });

        return $this->generate($rule, $now);
    }

    /** Un début (à l'heure du magasin) tombe-t-il encore un jour, à l'heure et dans les dates de la règle active ? */
    private function matches(BenefitSlotRule $rule, \DateTimeImmutable $local): bool
    {
        $ymd = $local->format('Y-m-d');

        return $rule->isActive() && $local->format('H:i') === $rule->getLocalTime() && in_array((int) $local->format('N'), $rule->getWeekdays(), true)
            && ($rule->getValidFrom() === null || $ymd >= $rule->getValidFrom()->format('Y-m-d')) && ($rule->getValidUntil() === null || $ymd <= $rule->getValidUntil()->format('Y-m-d'));
    }

    /**
     * Crée les créneaux de la règle sur 8 semaines devant ; sans effet sur ceux qui existent déjà (unicité règle + début). L'heure locale est
     * convertie en UTC pour CHAQUE jour, à l'heure (d'été ou d'hiver) de ce jour.
     */
    public function generate(BenefitSlotRule $rule, ?\DateTimeImmutable $now = null): int
    {
        if (!$rule->isActive() || $rule->getBenefit() === null || $rule->getId() === null) {
            return 0;
        }
        $now ??= new \DateTimeImmutable();
        $tz = $this->clock->timezone();
        $day = $now->setTimezone($tz)->setTime(0, 0);
        $conn = $this->conn();
        $created = 0;
        for ($i = 0; $i < self::GENERATE_DAYS; ++$i) {
            $date = $day->modify('+' . $i . ' days');
            $ymd = $date->format('Y-m-d');
            if (($rule->getValidFrom() !== null && $ymd < $rule->getValidFrom()->format('Y-m-d')) || ($rule->getValidUntil() !== null && $ymd > $rule->getValidUntil()->format('Y-m-d'))
                || !in_array((int) $date->format('N'), $rule->getWeekdays(), true)) {
                continue;
            }
            $start = new \DateTimeImmutable($ymd . ' ' . $rule->getLocalTime(), $tz); // l'heure de CE jour dans le fuseau du magasin
            if ($start <= $now) {
                continue;
            }
            $created += $conn->executeStatement('INSERT INTO benefit_slot (benefit_id, rule_id, starts_at, ends_at, capacity, location, staff_user_id, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (rule_id, starts_at) WHERE rule_id IS NOT NULL DO NOTHING',
                [$rule->getBenefit()->getId(), $rule->getId(), self::utc($start), self::utc($start->modify('+' . $rule->getDurationMinutes() . ' minutes')), $rule->getCapacity(),
                    $rule->getLocation(), $rule->getStaffUserId(), BenefitSlot::STATUS_OPEN, self::utc(new \DateTimeImmutable())]);
        }

        return $created;
    }

    /** Passage horaire : toutes les règles actives du site ; puis les places retenues échues depuis plus d'un jour sont effacées */
    public function generateAll(?\DateTimeImmutable $now = null): int
    {
        $created = 0;
        foreach ($this->emProvider->getEntityManager()->getRepository(BenefitSlotRule::class)->active() as $rule) {
            $created += $this->generate($rule, $now);
        }
        $this->convertPending();
        $this->conn()->executeStatement('DELETE FROM benefit_slot_hold WHERE expires_at < ?', [self::utc(($now ?? new \DateTimeImmutable())->modify('-1 day'))]);

        return $created;
    }

    /** Supprime une règle : ses créneaux futurs sans réservation partent avec elle, les autres restent (créneaux sans règle) */
    public function deleteRule(BenefitSlotRule $rule): void
    {
        $rule->setActive(false);
        $this->regenerate($rule);
        $em = $this->emProvider->getEntityManager();
        $em->remove($rule);
        $em->flush();
    }

    /**
     * Crée un créneau ponctuel (EasyAdmin).
     *
     * @throws AboException
     */
    public function createSlot(BenefitSlot $slot): BenefitSlot
    {
        $benefit = $slot->getBenefit() ?? throw new AboException(422, 'BENEFIT_NOT_FOUND', 'Choisissez la prestation.', 'benefitId');
        if (!in_array($benefit->getKind(), [Benefit::KIND_SESSION, Benefit::KIND_SERVICE], true)) {
            throw new AboException(422, 'SLOT_RULE_KIND_INVALID', 'Seules une séance ou un service se planifient.', 'benefitId');
        }
        if ($slot->getCapacity() < 1 || $slot->getCapacity() > BenefitSlotRule::CAPACITY_MAX) {
            throw new AboException(422, 'SLOT_RULE_CAPACITY_INVALID', sprintf('Capacité : de 1 à %d places.', BenefitSlotRule::CAPACITY_MAX), 'capacity');
        }
        if ($slot->getStartsAt() <= new \DateTimeImmutable() || $slot->getEndsAt() <= $slot->getStartsAt()) {
            throw new AboException(422, 'SLOT_DATE_INVALID', 'Un créneau commence dans le futur et finit après son début.', 'startsAt');
        }
        $this->assertStaff($slot->getStaffUserId());
        $slot->setRule(null)->setStatus(BenefitSlot::STATUS_OPEN);
        $em = $this->emProvider->getEntityManager();
        $em->persist($slot);
        $em->flush();

        return $slot;
    }

    /**
     * Les créneaux entre deux dates du magasin (bornes comprises), avec les places restantes. `$forTeam` faux (client, assistant) : jamais
     * l'intervenant.
     *
     * @return list<array<string, mixed>>
     * @throws AboException 422 RANGE_INVALID
     */
    public function slots(?int $benefitId, ?string $from, ?string $to, bool $forTeam, bool $openOnly = false): array
    {
        $tz = $this->clock->timezone();
        $start = $this->dateOrNull($from, 'from') ?? new \DateTimeImmutable('today', $tz);
        $end = $this->dateOrNull($to, 'to') ?? $start->modify('+13 days');
        $start = new \DateTimeImmutable($start->format('Y-m-d') . ' 00:00', $tz);
        $endExclusive = (new \DateTimeImmutable($end->format('Y-m-d') . ' 00:00', $tz))->modify('+1 day');
        if ($endExclusive <= $start || $endExclusive > $start->modify('+' . self::RANGE_MAX_DAYS . ' days')) {
            throw new AboException(422, 'RANGE_INVALID', sprintf('De 1 à %d jours.', self::RANGE_MAX_DAYS), 'to');
        }
        $rows = $this->emProvider->getEntityManager()->getRepository(BenefitSlot::class)->between($benefitId, $start->setTimezone(new \DateTimeZone('UTC')), $endExclusive->setTimezone(new \DateTimeZone('UTC')), $openOnly);

        return $this->slotViews($rows, $forTeam);
    }

    /** @param list<BenefitSlot> $rows @return list<array<string, mixed>> */
    public function slotViews(array $rows, bool $forTeam): array
    {
        $ids = array_map(fn (BenefitSlot $s) => (int) $s->getId(), $rows);
        $taken = $this->takenBySlot($ids);
        $out = [];
        foreach ($rows as $s) {
            $t = $taken[(int) $s->getId()] ?? ['booked' => 0, 'held' => 0];
            $view = ['slotId' => (int) $s->getId(), 'benefitId' => (int) $s->getBenefit()?->getId(), 'benefit' => $s->getBenefit()?->getName('fr'),
                'startsAt' => $this->at($s->getStartsAt()), 'endsAt' => $this->at($s->getEndsAt()), 'capacity' => $s->getCapacity(),
                'remaining' => $s->isOpen() ? max(0, $s->getCapacity() - $t['booked'] - $t['held']) : 0, 'location' => $s->getLocation(), 'status' => $s->getStatus()];
            if ($forTeam) {
                $view += ['booked' => $t['booked'], 'held' => $t['held'], 'staffUserId' => $s->getStaffUserId(), 'ruleId' => $s->getRule()?->getId(), 'cancelReason' => $s->getCancelReason()];
            }
            $out[] = $view;
        }

        return $out;
    }

    /**
     * Les créneaux ouverts au choix d'un abonné (ou d'un futur abonné à la souscription) pour une prestation de sa formule : 8 semaines devant.
     *
     * @return list<array<string, mixed>>
     * @throws AboException 404 BENEFIT_NOT_IN_PLAN, 403 SCHEDULING_NOT_BY_CLIENT
     */
    public function clientSlots(SubscriptionPlan $plan, int $benefitId, bool $atSubscription): array
    {
        $pb = $this->planBenefitOf($plan, $benefitId) ?? throw new AboException(404, 'BENEFIT_NOT_IN_PLAN', 'Cette prestation n\'est pas dans la formule.', 'benefitId');
        if (!self::clientChooses($pb, $atSubscription)) {
            throw new AboException(403, 'SCHEDULING_NOT_BY_CLIENT', 'Les dates de cette prestation sont attribuées par la boutique.', 'benefitId');
        }
        $tz = $this->clock->timezone();
        $rows = $this->emProvider->getEntityManager()->getRepository(BenefitSlot::class)->between($benefitId, new \DateTimeImmutable(), (new \DateTimeImmutable('today', $tz))->modify('+' . self::GENERATE_DAYS . ' days')->setTimezone(new \DateTimeZone('UTC')), true);

        return $this->slotViews($rows, false);
    }

    /**
     * Annule un créneau (la boutique) : ses droits planifiés ou annulés trop tard redeviennent à planifier, sans perte ; chaque abonné est
     * prévenu par courriel (Q2). Motif obligatoire ; au journal du site.
     *
     * @return array{slotId: int, released: int}
     * @throws AboException 404 SLOT_NOT_FOUND, 409 SLOT_CANCELLED, 422 REASON_REQUIRED
     */
    public function cancelSlot(int $slotId, ?string $reason, ?string $by, string $host = '', string $locale = 'fr'): array
    {
        $reason = trim(strip_tags((string) $reason));
        if ($reason === '') {
            throw new AboException(422, 'REASON_REQUIRED', 'Donnez le motif de l\'annulation : il est envoyé aux abonnés.', 'reason');
        }
        $em = $this->emProvider->getEntityManager();
        $conn = $this->conn();
        $affected = $conn->transactional(function (Connection $conn) use ($slotId, $reason) {
            $this->lockSlot($conn, $slotId);
            $row = $conn->fetchAssociative('SELECT id, status FROM benefit_slot WHERE id = ?', [$slotId]);
            if ($row === false) {
                throw new AboException(404, 'SLOT_NOT_FOUND', 'Créneau introuvable.');
            }
            if ($row['status'] !== BenefitSlot::STATUS_OPEN) {
                throw new AboException(409, 'SLOT_CANCELLED', 'Ce créneau est déjà annulé.');
            }
            $conn->executeStatement('UPDATE benefit_slot SET status = ?, cancelled_at = ?, cancel_reason = ? WHERE id = ?', [BenefitSlot::STATUS_CANCELLED, self::utc(new \DateTimeImmutable()), mb_substr($reason, 0, 300), $slotId]);
            // relecture neuve : lignes verrouillées (un déplacement en cours finit d'abord, et sa ligne n'est plus sur ce créneau) ; seules les
            // lignes réellement rendues comptent et reçoivent un courriel
            $rows = $conn->fetchAllAssociative("SELECT o.id, o.subscription_id FROM benefit_occurrence o WHERE o.slot_id = ? AND o.status IN ('scheduled', 'late_cancelled') ORDER BY o.id FOR UPDATE", [$slotId]);
            $released = [];
            foreach ($rows as $r) {
                if ($this->returnToPlan($conn, (int) $r['id'], (int) $r['subscription_id'], $slotId) === 1) {
                    $released[] = $r;
                }
            }
            $conn->executeStatement('DELETE FROM benefit_slot_hold WHERE slot_id = ?', [$slotId]);

            return $released;
        });
        $this->audit?->record('benefit_slot', (string) $slotId, 'cancel', null, ['reason' => $reason, 'released' => count($affected)], ['abonnements']); // avant les courriels
        // le créneau tel que le SQL ci-dessus l'a laissé (annulé, motif) : un exemplaire déjà chargé dans le gestionnaire est relu, jamais
        // laissé « ouvert » pour la suite de la requête (remplace clear(BenefitSlot::class), déprécié)
        $slot = $em->getRepository(BenefitSlot::class)->find($slotId);
        if ($slot !== null) {
            $em->refresh($slot);
        }
        $bySubscription = [];
        foreach ($affected as $r) {
            $bySubscription[(int) $r['subscription_id']] = ($bySubscription[(int) $r['subscription_id']] ?? 0) + 1;
        }
        foreach ($bySubscription as $subscriptionId => $n) {
            $subscription = $em->getRepository(Subscription::class)->find($subscriptionId);
            if ($subscription === null) {
                continue;
            }
            $em->persist(new SubscriptionAboEvent($subscription, 'slot_cancelled', $slot?->getBenefit()?->getId(), $n, $reason, $by));
            $this->mailer?->notify($subscription, 'session_cancelled', $locale, $host, ['rows' => [
                ($locale === 'en' ? 'Session' : 'Séance') => (string) $slot?->getBenefit()?->getName($locale),
                ($locale === 'en' ? 'Date' : 'Date') => $slot !== null ? $slot->getStartsAt()->setTimezone($this->clock->timezone())->format('d/m/Y H:i') : '',
                ($locale === 'en' ? 'Reason' : 'Motif') => $reason,
            ]]);
        }
        $em->flush();

        return ['slotId' => $slotId, 'released' => count($affected)];
    }

    // ---- dates des droits --------------------------------------------------------------------------------------------------------------

    /**
     * Donne une date à un droit à planifier : un créneau (`slotId`) ou, en mode `admin_after`, une date libre (`startsAt`, équipe seulement).
     * Sans `occurrenceId`, le droit à planifier qui expire le premier (pour l'abonné : le premier encore valable au début du créneau).
     *
     * @param 'client'|'team' $source
     * @return array<string, mixed> le rendez-vous
     * @throws AboException
     */
    public function book(Subscription $subscription, int $benefitId, ?int $occurrenceId, ?int $slotId, ?string $startsAt, string $source, ?string $by): array
    {
        return $this->locked($subscription, fn () => $this->conn()->transactional(fn (Connection $conn) => $this->bookIn($conn, $subscription, $benefitId, $occurrenceId, $slotId, $startsAt, $source, $by)));
    }

    /**
     * Déplace une date : annuler puis réserver, d'un seul tenant (un refus laisse l'ancienne date). L'abonné : avant le délai seulement (409
     * CANCEL_DEADLINE_PASSED) ; l'équipe : à tout moment.
     *
     * @param 'client'|'team' $source
     * @return array<string, mixed>
     * @throws AboException
     */
    public function move(Subscription $subscription, int $occurrenceId, ?int $slotId, ?string $startsAt, string $source, ?string $by): array
    {
        return $this->locked($subscription, function () use ($subscription, $occurrenceId, $slotId, $startsAt, $source, $by) {
            return $this->conn()->transactional(function (Connection $conn) use ($subscription, $occurrenceId, $slotId, $startsAt, $source, $by) {
                $o = $this->scheduledRow($conn, $subscription, $occurrenceId);
                if ($source === BenefitOccurrence::SOURCE_CLIENT && $this->pastDeadline($o)) {
                    throw new AboException(409, 'CANCEL_DEADLINE_PASSED', sprintf('Le délai pour déplacer ce rendez-vous est passé (%d h avant).', $this->deadlineHours($o)));
                }
                $this->clearDate($conn, (int) $o['id']);
                $view = $this->bookIn($conn, $subscription, (int) $o['benefit_id'], (int) $o['id'], $slotId, $startsAt, $source, $by, false, true);
                $this->event($subscription, 'moved', (int) $o['benefit_id'], $by, $source);
                if ($source === BenefitOccurrence::SOURCE_TEAM) {
                    $this->journal($subscription, 'appointment_move', ['occurrenceId' => (int) $o['id'], 'slotId' => $slotId, 'startsAt' => $view['startsAt']]);
                }

                return $view;
            });
        });
    }

    /**
     * Annule une date. L'abonné : avant le délai, le droit redevient à planifier ; après, il passe `late_cancelled` (compté comme utilisé).
     * L'équipe : toujours sans perte, au journal (Q3).
     *
     * @param 'client'|'team' $source
     * @return array{occurrenceId: int, status: string}
     * @throws AboException 404 OCCURRENCE_NOT_FOUND
     */
    public function cancelDate(Subscription $subscription, int $occurrenceId, string $source, ?string $by): array
    {
        return $this->locked($subscription, function () use ($subscription, $occurrenceId, $source, $by) {
            return $this->conn()->transactional(function (Connection $conn) use ($subscription, $occurrenceId, $source, $by) {
                $o = $this->scheduledRow($conn, $subscription, $occurrenceId);
                if ($source === BenefitOccurrence::SOURCE_CLIENT && $this->pastDeadline($o)) {
                    $conn->executeStatement('UPDATE benefit_occurrence SET status = ?, done_at = ? WHERE id = ?', [BenefitOccurrence::STATUS_LATE_CANCELLED, self::utc(new \DateTimeImmutable()), (int) $o['id']]);
                    $this->event($subscription, 'late_cancelled', (int) $o['benefit_id'], $by, $source);

                    return ['occurrenceId' => (int) $o['id'], 'status' => BenefitOccurrence::STATUS_LATE_CANCELLED];
                }
                $this->returnToPlan($conn, (int) $o['id'], (int) $subscription->getId());
                $this->event($subscription, 'unscheduled', (int) $o['benefit_id'], $by, $source);
                if ($source === BenefitOccurrence::SOURCE_TEAM) {
                    $this->journal($subscription, 'appointment_cancel', ['occurrenceId' => (int) $o['id']]);
                }

                return ['occurrenceId' => (int) $o['id'], 'status' => BenefitOccurrence::STATUS_AVAILABLE];
            });
        });
    }

    /**
     * Les droits à planifier du site, par abonnement (prestations dont les dates se donnent : `admin_after`, `fixed_slots`,
     * `client_at_subscription`). `$withIdentity` faux (assistant) : le numéro d'abonnement seulement.
     *
     * @return list<array<string, mixed>>
     */
    public function toSchedule(bool $withIdentity, int $limit = 200): array
    {
        $rows = $this->conn()->fetchAllAssociative("SELECT o.subscription_id, o.benefit_id, pb.scheduling, pb.slot_choice, COUNT(*) AS n, MIN(o.valid_until) AS next_expiry
            FROM benefit_occurrence o JOIN subscription s ON s.id = o.subscription_id JOIN plan_benefit pb ON pb.plan_id = s.plan_id AND pb.benefit_id = o.benefit_id
            WHERE o.status = 'available' AND o.valid_until > ? AND pb.scheduling <> 'none' AND s.status IN ('active', 'trialing', 'past_due')
            GROUP BY o.subscription_id, o.benefit_id, pb.scheduling, pb.slot_choice ORDER BY MIN(o.valid_until), o.subscription_id LIMIT " . max(1, min(500, $limit)), [self::utc(new \DateTimeImmutable())]);
        $em = $this->emProvider->getEntityManager();
        $out = [];
        foreach ($rows as $r) {
            $sid = (int) $r['subscription_id'];
            if (!isset($out[$sid])) {
                $s = $em->getRepository(Subscription::class)->find($sid);
                $out[$sid] = ['subscriptionId' => $sid, 'plan' => $s?->getPlan()?->getName('fr'), 'status' => $s?->getStatus(), 'benefits' => []];
                if ($withIdentity) {
                    $u = $s?->getUser();
                    $out[$sid]['customer'] = $u !== null ? ['id' => (int) $u->getId(), 'firstname' => $u->getFirstname(), 'lastname' => $u->getLastname()] : null;
                }
            }
            $out[$sid]['benefits'][] = ['benefitId' => (int) $r['benefit_id'], 'name' => $this->benefits->benefits()->find((int) $r['benefit_id'])?->getName('fr'), 'count' => (int) $r['n'],
                'scheduling' => (string) $r['scheduling'], 'slotChoice' => $r['slot_choice'], 'nextExpiry' => $this->at(new \DateTimeImmutable((string) $r['next_expiry'], new \DateTimeZone('UTC')))];
        }

        return array_values($out);
    }

    /**
     * Les prochaines dates d'un abonnement (10 au plus). `$forTeam` faux (client) : jamais l'intervenant.
     *
     * @return list<array<string, mixed>>
     */
    public function upcoming(Subscription $subscription, bool $forTeam, string $locale = 'fr'): array
    {
        $rows = $this->conn()->fetchAllAssociative("SELECT o.id, o.benefit_id, o.slot_id, o.scheduled_at, o.scheduled_end, o.scheduled_source, sl.location AS slot_location, " . AboStaffService::EFFECTIVE . " AS staff_user_id
            FROM benefit_occurrence o JOIN benefit b ON b.id = o.benefit_id LEFT JOIN benefit_slot sl ON sl.id = o.slot_id WHERE o.subscription_id = ? AND o.status = 'scheduled' AND o.scheduled_end > ?
            ORDER BY o.scheduled_at, o.id LIMIT " . self::UPCOMING_MAX, [$subscription->getId(), self::utc(new \DateTimeImmutable())]);
        $out = [];
        // lot ABO-3b : l'intervenant effectif (date, sinon créneau, sinon prestation) et son nom, pour l'équipe seulement
        $staffNames = $forTeam ? $this->staffNames(array_map(fn (array $r) => $r['staff_user_id'] !== null ? (int) $r['staff_user_id'] : 0, $rows)) : [];
        foreach ($rows as $r) {
            $b = $this->benefits->benefits()->find((int) $r['benefit_id']);
            $pb = $subscription->getPlan() !== null ? $this->planBenefitOf($subscription->getPlan(), (int) $r['benefit_id']) : null;
            $start = new \DateTimeImmutable((string) $r['scheduled_at'], new \DateTimeZone('UTC'));
            $view = ['occurrenceId' => (int) $r['id'], 'benefitId' => (int) $r['benefit_id'], 'name' => $b?->getName($locale), 'startsAt' => $this->at($start),
                'endsAt' => $this->at(new \DateTimeImmutable((string) $r['scheduled_end'], new \DateTimeZone('UTC'))), 'location' => $r['slot_location'] ?? $b?->getLocation(),
                'slotId' => $r['slot_id'] !== null ? (int) $r['slot_id'] : null,
                // jusqu'à quand l'abonné peut annuler sans perdre son droit
                'cancellableUntil' => $this->at($start->modify('-' . ($pb?->getCancelDeadlineHours() ?? PlanBenefit::DEFAULT_CANCEL_HOURS) . ' hours'))];
            if ($forTeam) {
                $staffId = $r['staff_user_id'] !== null ? (int) $r['staff_user_id'] : null;
                $view += ['staffUserId' => $staffId, 'staffName' => $staffId !== null ? ($staffNames[$staffId] ?? null) : null, 'source' => $r['scheduled_source']];
            }
            $out[] = $view;
        }

        return $out;
    }

    // ---- places retenues pendant le paiement (Q1) ------------------------------------------------------------------------------------

    /**
     * Retient pour 30 minutes les places choisies à la souscription (`slots: [{ benefitId, slotId }]`), avant le paiement : une prestation
     * `client_at_subscription` de la formule, un créneau ouvert et futur de cette prestation, autant de places au plus que de droits d'une
     * période (quantité × places de l'abonnement) ; capacité vérifiée sous le verrou du créneau.
     *
     * @param mixed $slots
     * @return list<int> les places retenues
     * @throws AboException 422 SLOTS_INVALID, 404 BENEFIT_NOT_IN_PLAN | SLOT_NOT_FOUND, 403 SCHEDULING_NOT_BY_CLIENT, 409 SLOT_FULL | SLOT_PAST
     */
    public function hold(User $user, SubscriptionPlan $plan, int $quantity, mixed $slots): array
    {
        if ($slots === null || $slots === []) {
            return [];
        }
        if (!is_array($slots) || !array_is_list($slots) || count($slots) > 50) {
            throw new AboException(422, 'SLOTS_INVALID', 'slots : liste de { benefitId, slotId } attendue.', 'slots');
        }
        $wanted = [];
        foreach ($slots as $row) {
            if (!is_array($row) || !is_int($row['benefitId'] ?? null) || !is_int($row['slotId'] ?? null)) {
                throw new AboException(422, 'SLOTS_INVALID', 'slots : liste de { benefitId, slotId } attendue.', 'slots');
            }
            $pb = $this->planBenefitOf($plan, $row['benefitId']) ?? throw new AboException(404, 'BENEFIT_NOT_IN_PLAN', 'Cette prestation n\'est pas dans la formule.', 'slots');
            if ($pb->getScheduling() !== PlanBenefit::SCHEDULING_CLIENT) {
                throw new AboException(403, 'SCHEDULING_NOT_BY_CLIENT', 'Les dates de cette prestation ne se choisissent pas à la souscription.', 'slots');
            }
            if ($pb->getQuantity() === null) {
                throw new AboException(422, 'SLOTS_INVALID', 'Une prestation sans limite ne se planifie pas.', 'slots');
            }
            $wanted[$row['benefitId']][] = $row['slotId'];
            if ($pb->getQuantity() !== null && count($wanted[$row['benefitId']]) > $pb->getQuantity() * max(1, $quantity)) {
                throw new AboException(422, 'SLOTS_INVALID', 'Plus de dates que de séances comprises dans une période.', 'slots');
            }
            if (count(array_unique($wanted[$row['benefitId']])) !== count($wanted[$row['benefitId']])) {
                throw new AboException(409, 'SLOT_ALREADY_BOOKED', 'Un même créneau ne se prend qu\'une fois.', 'slots');
            }
        }
        $ids = [];
        try {
            foreach ($wanted as $benefitId => $slotIds) {
                foreach ($slotIds as $slotId) {
                    $ids[] = $this->conn()->transactional(function (Connection $conn) use ($user, $plan, $benefitId, $slotId) {
                        $this->lockSlot($conn, $slotId);
                        $this->assertBookableSlot($conn, $slotId, (int) $benefitId);
                        $this->assertRoom($conn, $slotId, null);

                        return (int) $conn->fetchOne('INSERT INTO benefit_slot_hold (slot_id, benefit_id, user_id, plan_id, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
                            [$slotId, $benefitId, $user->getId(), $plan->getId(), self::utc((new \DateTimeImmutable())->modify('+' . self::HOLD_MINUTES . ' minutes')), self::utc(new \DateTimeImmutable())]);
                    });
                }
            }
        } catch (\Throwable $e) {
            $this->releaseHolds($ids);
            throw $e;
        }

        return $ids;
    }

    /** @param list<int> $ids */
    public function releaseHolds(array $ids): void
    {
        if ($ids !== []) {
            $this->conn()->executeStatement('DELETE FROM benefit_slot_hold WHERE id IN (?) AND subscription_id IS NULL', [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        }
    }

    /** Les places retenues rattachées à l'abonnement créé (une souscription reprise remplace les places d'avant) @param list<int> $ids */
    public function attachHolds(array $ids, Subscription $subscription): void
    {
        if ($ids === []) {
            return;
        }
        $conn = $this->conn();
        $conn->executeStatement('DELETE FROM benefit_slot_hold WHERE subscription_id = ? AND id NOT IN (?)', [$subscription->getId(), $ids], [\PDO::PARAM_INT, \Doctrine\DBAL\ArrayParameterType::INTEGER]);
        $conn->executeStatement('UPDATE benefit_slot_hold SET subscription_id = ? WHERE id IN (?)', [$subscription->getId(), $ids], [\PDO::PARAM_INT, \Doctrine\DBAL\ArrayParameterType::INTEGER]);
    }

    /**
     * À la naissance des droits (facture payée) : chaque place retenue de l'abonnement devient un rendez-vous sur un droit à planifier. Une
     * place retenue échue n'est convertie que s'il reste de la place ; sinon le droit reste à planifier (événement `hold_lost`). Appelé sous
     * le verrou de l'abonnement (webhook, passage).
     */
    public function convertHolds(Subscription $subscription, bool $onlyIfBorn = false): int
    {
        $conn = $this->conn();
        $holds = $conn->fetchAllAssociative('SELECT id, slot_id, benefit_id FROM benefit_slot_hold WHERE subscription_id = ? ORDER BY id', [$subscription->getId()]);
        $converted = 0;
        foreach ($holds as $h) {
            if ($onlyIfBorn && $conn->fetchOne("SELECT 1 FROM benefit_occurrence WHERE subscription_id = ? AND benefit_id = ? AND status = 'available' LIMIT 1", [$subscription->getId(), (int) $h['benefit_id']]) === false) {
                continue; // droits pas encore nés (paiement en attente) : la place attend sa facture
            }
            try {
                $conn->transactional(function (Connection $conn) use ($subscription, $h) {
                    $this->lockSlot($conn, (int) $h['slot_id']);
                    $conn->executeStatement('DELETE FROM benefit_slot_hold WHERE id = ?', [(int) $h['id']]); // la place retenue cède la place au rendez-vous
                    $this->assertBookableSlot($conn, (int) $h['slot_id'], (int) $h['benefit_id']);
                    $this->assertRoom($conn, (int) $h['slot_id'], (int) $subscription->getId());
                    $slot = $conn->fetchAssociative('SELECT starts_at, ends_at FROM benefit_slot WHERE id = ?', [(int) $h['slot_id']]);
                    $id = $this->pickOccurrence($conn, $subscription, (int) $h['benefit_id'], null, null);
                    $this->writeDate($conn, $id, (int) $h['slot_id'], (string) $slot['starts_at'], (string) $slot['ends_at'], BenefitOccurrence::SOURCE_CLIENT, null);
                });
                ++$converted;
            } catch (AboException $e) {
                if ($e->errorCode === 'SLOT_BUSY') {
                    continue; // créneau tenu un instant : la place reste retenue, reprise au passage horaire (convertPending)
                }
                $conn->executeStatement('DELETE FROM benefit_slot_hold WHERE id = ?', [(int) $h['id']]);
                $this->event($subscription, 'hold_lost', (int) $h['benefit_id'], null, BenefitOccurrence::SOURCE_CLIENT);
            }
        }
        if ($converted > 0) {
            $this->event($subscription, 'scheduled', null, null, BenefitOccurrence::SOURCE_CLIENT, $converted);
        }

        return $converted;
    }

    /** Convertit tout de suite les places retenues, sous le verrou de l'abonnement (souscription reprise dont les droits sont déjà nés) */
    public function convertNow(Subscription $subscription): int
    {
        return $this->locked($subscription, fn () => $this->convertHolds($subscription, true));
    }

    /**
     * Passage horaire : les places retenues d'abonnements dont les droits sont DÉJÀ nés (conversion manquée : créneau tenu un instant) sont
     * reprises. Une place d'un abonnement pas encore payé attend sa facture.
     */
    public function convertPending(): int
    {
        $n = 0;
        $ids = $this->conn()->fetchFirstColumn("SELECT DISTINCT h.subscription_id FROM benefit_slot_hold h WHERE h.subscription_id IS NOT NULL
            AND EXISTS (SELECT 1 FROM benefit_occurrence o WHERE o.subscription_id = h.subscription_id AND o.benefit_id = h.benefit_id AND o.status = 'available')");
        foreach ($ids as $id) {
            $s = $this->emProvider->getEntityManager()->getRepository(Subscription::class)->find((int) $id);
            if ($s !== null) {
                try {
                    $n += $this->convertNow($s);
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf('[Abo] places retenues de l\'abonnement %d non converties (%s)', (int) $id, $e::class));
                }
            }
        }

        return $n;
    }

    // ---- interne -------------------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function bookIn(Connection $conn, Subscription $subscription, int $benefitId, ?int $occurrenceId, ?int $slotId, ?string $startsAt, string $source, ?string $by, bool $withEvent = true, bool $moving = false): array
    {
        $byClient = $source === BenefitOccurrence::SOURCE_CLIENT;
        if (!in_array($subscription->getStatus(), $byClient ? self::CLIENT_STATUSES : self::TEAM_STATUSES, true)) {
            throw new AboException(409, 'SUBSCRIPTION_NOT_ACTIVE', 'Cet abonnement n\'est pas en cours : aucune date ne se planifie.');
        }
        $plan = $subscription->getPlan();
        $pb = $plan !== null ? $this->planBenefitOf($plan, $benefitId) : null;
        if ($pb === null) {
            throw new AboException(404, 'BENEFIT_NOT_IN_PLAN', 'Cette prestation n\'est pas dans la formule de l\'abonnement.', 'benefitId');
        }
        $mode = $pb->getScheduling();
        if ($mode === PlanBenefit::SCHEDULING_NONE) {
            throw new AboException(422, 'SCHEDULING_NONE', 'Cette prestation ne se planifie pas.', 'benefitId');
        }
        if ($byClient && !self::clientChooses($pb, false)) {
            throw new AboException(403, 'SCHEDULING_NOT_BY_CLIENT', 'Les dates de cette prestation sont attribuées par la boutique.', 'benefitId');
        }
        if ($slotId !== null) {
            if ($mode === PlanBenefit::SCHEDULING_ADMIN) {
                throw new AboException(422, 'DATE_REQUIRED', 'Cette prestation se planifie à une date libre (startsAt).', 'startsAt');
            }
            $this->lockSlot($conn, $slotId);
            $slot = $this->assertBookableSlot($conn, $slotId, $benefitId);
            if ($conn->fetchOne("SELECT 1 FROM benefit_occurrence WHERE subscription_id = ? AND slot_id = ? AND status = 'scheduled' LIMIT 1", [$subscription->getId(), $slotId]) !== false) {
                throw new AboException(409, 'SLOT_ALREADY_BOOKED', 'Cet abonnement a déjà une place sur ce créneau.', 'slotId');
            }
            $this->assertRoom($conn, $slotId, null);
            $id = $this->pickOccurrence($conn, $subscription, $benefitId, $occurrenceId, $byClient ? (string) $slot['starts_at'] : null, $moving && !$byClient);
            $this->writeDate($conn, $id, $slotId, (string) $slot['starts_at'], (string) $slot['ends_at'], $source, $by);
        } else {
            if ($mode !== PlanBenefit::SCHEDULING_ADMIN || $byClient) {
                throw new AboException(422, 'SLOT_REQUIRED', 'Choisissez un créneau (slotId).', 'slotId');
            }
            $start = $this->parseStart($startsAt);
            $end = $start->modify('+' . ($pb->getBenefit()?->getDurationMinutes() ?? self::DEFAULT_DURATION) . ' minutes');
            $id = $this->pickOccurrence($conn, $subscription, $benefitId, $occurrenceId, null, $moving);
            $this->writeDate($conn, $id, null, self::utc($start), self::utc($end), $source, $by);
        }
        if ($withEvent) {
            $this->event($subscription, 'scheduled', $benefitId, $by, $source);
            if (!$byClient) {
                $this->journal($subscription, 'appointment_set', ['occurrenceId' => $id, 'slotId' => $slotId, 'startsAt' => $startsAt]);
            }
        }

        return $this->appointment($conn, $id);
    }

    /** @return array<string, mixed> */
    private function appointment(Connection $conn, int $id): array
    {
        $r = $conn->fetchAssociative('SELECT id, benefit_id, slot_id, scheduled_at, scheduled_end, status FROM benefit_occurrence WHERE id = ?', [$id]);

        return ['occurrenceId' => $id, 'benefitId' => (int) $r['benefit_id'], 'slotId' => $r['slot_id'] !== null ? (int) $r['slot_id'] : null, 'status' => (string) $r['status'],
            'startsAt' => $this->at(new \DateTimeImmutable((string) $r['scheduled_at'], new \DateTimeZone('UTC'))), 'endsAt' => $this->at(new \DateTimeImmutable((string) $r['scheduled_end'], new \DateTimeZone('UTC')))];
    }

    /**
     * Le droit à planifier à dater : celui demandé, sinon celui qui expire le premier (pour l'abonné : valable encore au début du créneau).
     *
     * @throws AboException 404 OCCURRENCE_NOT_FOUND, 409 BALANCE_EMPTY | SLOT_AFTER_VALIDITY
     */
    private function pickOccurrence(Connection $conn, Subscription $subscription, int $benefitId, ?int $occurrenceId, ?string $validAt, bool $anyValidity = false): int
    {
        $now = self::utc(new \DateTimeImmutable());
        if ($occurrenceId !== null) {
            $r = $conn->fetchAssociative('SELECT id, status, valid_until FROM benefit_occurrence WHERE id = ? AND subscription_id = ? AND benefit_id = ? FOR UPDATE', [$occurrenceId, $subscription->getId(), $benefitId]);
            // relecture neuve : l'équipe déplace à tout moment, même une date déjà passée ou au-delà de la validité (`$anyValidity`)
            if ($r === false || $r['status'] !== BenefitOccurrence::STATUS_AVAILABLE || (!$anyValidity && (string) $r['valid_until'] <= $now)) {
                throw new AboException(404, 'OCCURRENCE_NOT_FOUND', 'Droit à planifier introuvable.', 'occurrenceId');
            }
            if ($validAt !== null && (string) $r['valid_until'] < $validAt) {
                throw new AboException(409, 'SLOT_AFTER_VALIDITY', 'Ce créneau est après la fin de validité de votre séance.', 'slotId');
            }

            return (int) $r['id'];
        }
        // deux gestes simultanés ne prennent jamais le même droit : ligne verrouillée, les autres sautées
        $id = $conn->fetchOne("SELECT id FROM benefit_occurrence WHERE subscription_id = ? AND benefit_id = ? AND status = 'available' AND valid_until > ?" . ($validAt !== null ? ' AND valid_until >= ?' : '') . '
            ORDER BY valid_until, id LIMIT 1 FOR UPDATE SKIP LOCKED', array_values(array_filter([$subscription->getId(), $benefitId, $now, $validAt], fn ($v) => $v !== null)));
        if ($id === false) {
            if ($validAt !== null && $conn->fetchOne("SELECT 1 FROM benefit_occurrence WHERE subscription_id = ? AND benefit_id = ? AND status = 'available' AND valid_until > ? LIMIT 1", [$subscription->getId(), $benefitId, $now]) !== false) {
                throw new AboException(409, 'SLOT_AFTER_VALIDITY', 'Ce créneau est après la fin de validité de vos séances : choisissez une date plus proche.', 'slotId');
            }
            throw new AboException(409, 'BALANCE_EMPTY', 'Plus aucun droit à planifier pour cette prestation.', 'benefitId');
        }

        return (int) $id;
    }

    private function writeDate(Connection $conn, int $id, ?int $slotId, string $startUtc, string $endUtc, string $source, ?string $by): void
    {
        $n = $conn->executeStatement("UPDATE benefit_occurrence SET status = 'scheduled', slot_id = ?, scheduled_at = ?, scheduled_end = ?, scheduled_by = ?, scheduled_source = ? WHERE id = ? AND status = 'available'",
            [$slotId, $startUtc, $endUtc, $by !== null ? mb_substr($by, 0, 180) : null, $source, $id]);
        if ($n !== 1) {
            throw new AboException(409, 'OCCURRENCE_NOT_AVAILABLE', 'Ce droit vient d\'être utilisé ou planifié : réessayez.');
        }
    }

    /**
     * Le droit redevient à planifier, sans perte : s'il a dépassé sa validité, il vaut jusqu'à la fin de la période en cours (ou 30 jours).
     * Avec `$fromSlot`, seulement s'il est encore sur ce créneau. Rend le nombre de lignes rendues (0 ou 1).
     */
    private function returnToPlan(Connection $conn, int $id, int $subscriptionId, ?int $fromSlot = null): int
    {
        $now = new \DateTimeImmutable();
        $floor = self::floor($conn, $subscriptionId);

        return $conn->executeStatement("UPDATE benefit_occurrence SET status = 'available', slot_id = NULL, scheduled_at = NULL, scheduled_end = NULL, scheduled_by = NULL, scheduled_source = NULL, done_at = NULL,
            valid_until = CASE WHEN valid_until <= ? THEN ? ELSE valid_until END WHERE id = ? AND status IN ('scheduled', 'late_cancelled')" . ($fromSlot !== null ? ' AND slot_id = ' . $fromSlot : ''),
            [self::utc($now), $floor, $id]);
    }

    /** La validité d'un droit rendu après sa fin : la fin de la période en cours si elle est à venir, sinon dans 30 jours (UTC) */
    public static function floor(Connection $conn, int $subscriptionId): string
    {
        $end = $conn->fetchOne('SELECT current_period_end FROM subscription WHERE id = ?', [$subscriptionId]);
        $now = new \DateTimeImmutable();

        return is_string($end) && new \DateTimeImmutable($end, new \DateTimeZone('UTC')) > $now ? $end : self::utc($now->modify('+30 days'));
    }

    private function clearDate(Connection $conn, int $id): void
    {
        $conn->executeStatement("UPDATE benefit_occurrence SET status = 'available', slot_id = NULL, scheduled_at = NULL, scheduled_end = NULL, scheduled_by = NULL, scheduled_source = NULL WHERE id = ?", [$id]);
    }

    /** @return array<string, mixed> @throws AboException 404 OCCURRENCE_NOT_FOUND */
    private function scheduledRow(Connection $conn, Subscription $subscription, int $occurrenceId): array
    {
        $o = $conn->fetchAssociative("SELECT o.*, s.plan_id FROM benefit_occurrence o JOIN subscription s ON s.id = o.subscription_id WHERE o.id = ? AND o.subscription_id = ? AND o.status = 'scheduled' FOR UPDATE OF o",
            [$occurrenceId, $subscription->getId()]);

        return $o !== false ? $o : throw new AboException(404, 'OCCURRENCE_NOT_FOUND', 'Rendez-vous introuvable.', 'occurrenceId');
    }

    /** @param array<string, mixed> $o */
    private function pastDeadline(array $o): bool
    {
        $start = new \DateTimeImmutable((string) $o['scheduled_at'], new \DateTimeZone('UTC'));

        return $start->modify('-' . $this->deadlineHours($o) . ' hours') <= new \DateTimeImmutable();
    }

    /** @param array<string, mixed> $o */
    private function deadlineHours(array $o): int
    {
        $h = $this->conn()->fetchOne('SELECT cancel_deadline_hours FROM plan_benefit WHERE plan_id = ? AND benefit_id = ? LIMIT 1', [$o['plan_id'], $o['benefit_id']]);

        return $h !== false ? (int) $h : PlanBenefit::DEFAULT_CANCEL_HOURS;
    }

    /** @return array<string, mixed> @throws AboException 404 SLOT_NOT_FOUND, 409 SLOT_CANCELLED | SLOT_PAST */
    private function assertBookableSlot(Connection $conn, int $slotId, int $benefitId): array
    {
        $slot = $conn->fetchAssociative('SELECT id, benefit_id, starts_at, ends_at, capacity, status FROM benefit_slot WHERE id = ?', [$slotId]);
        if ($slot === false || (int) $slot['benefit_id'] !== $benefitId) {
            throw new AboException(404, 'SLOT_NOT_FOUND', 'Créneau introuvable pour cette prestation.', 'slotId');
        }
        if ($slot['status'] !== BenefitSlot::STATUS_OPEN) {
            throw new AboException(409, 'SLOT_CANCELLED', 'Ce créneau est annulé.', 'slotId');
        }
        if ((string) $slot['starts_at'] <= self::utc(new \DateTimeImmutable())) {
            throw new AboException(409, 'SLOT_PAST', 'Ce créneau est déjà commencé ou passé.', 'slotId');
        }

        return $slot;
    }

    /**
     * Sous le verrou du créneau : la place restante compte les droits planifiés et les places retenues encore valables (celles de
     * `$ownSubscription` exceptées : sa place retenue devient son rendez-vous).
     *
     * @throws AboException 409 SLOT_FULL
     */
    private function assertRoom(Connection $conn, int $slotId, ?int $ownSubscription): void
    {
        $capacity = (int) $conn->fetchOne('SELECT capacity FROM benefit_slot WHERE id = ?', [$slotId]);
        // relecture neuve d'ABO-3a : un « fait » ou un « absent » posé le jour même, avant l'heure, garde sa place (aucune surréservation
        // si le constat est ensuite corrigé)
        $booked = (int) $conn->fetchOne("SELECT COUNT(*) FROM benefit_occurrence WHERE slot_id = ? AND status IN ('scheduled', 'done', 'missed')", [$slotId]);
        $held = (int) ($ownSubscription === null
            ? $conn->fetchOne('SELECT COUNT(*) FROM benefit_slot_hold WHERE slot_id = ? AND expires_at > ?', [$slotId, self::utc(new \DateTimeImmutable())])
            : $conn->fetchOne('SELECT COUNT(*) FROM benefit_slot_hold WHERE slot_id = ? AND expires_at > ? AND (subscription_id IS NULL OR subscription_id <> ?)', [$slotId, self::utc(new \DateTimeImmutable()), $ownSubscription]));
        if ($booked + $held >= $capacity) {
            throw new AboException(409, 'SLOT_FULL', 'Ce créneau est complet.', 'slotId');
        }
    }

    /**
     * Le verrou du créneau, pour la transaction en cours ; attente bornée (un créneau tenu trop longtemps : 409 SLOT_BUSY, jamais une
     * attente sans fin).
     *
     * @throws AboException 409 SLOT_BUSY
     */
    private function lockSlot(Connection $conn, int $slotId): void
    {
        $before = (string) $conn->fetchOne('SHOW lock_timeout'); // le réglage de la session (un essai en pose un) est rendu après
        $conn->executeStatement(sprintf("SET LOCAL lock_timeout = '%dms'", self::LOCK_TIMEOUT_MS));
        try {
            $conn->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['benefit_slot:' . $this->tenant->getTenantCode() . ':' . $slotId]);
        } catch (\Doctrine\DBAL\Exception $e) {
            throw new AboException(409, 'SLOT_BUSY', 'Ce créneau est en cours de réservation : réessayez dans un instant.', 'slotId', previous: $e);
        }
        $conn->executeStatement('SELECT set_config(\'lock_timeout\', ?, true)', [$before]);
    }

    /** @param list<int> $ids @return array<int, array{booked: int, held: int}> */
    private function takenBySlot(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $types = [\Doctrine\DBAL\ArrayParameterType::INTEGER];
        foreach ($this->conn()->fetchAllAssociative("SELECT slot_id, COUNT(*) AS n FROM benefit_occurrence WHERE slot_id IN (?) AND status IN ('scheduled', 'done', 'missed') GROUP BY slot_id", [$ids], $types) as $r) {
            $out[(int) $r['slot_id']]['booked'] = (int) $r['n'];
        }
        foreach ($this->conn()->fetchAllAssociative('SELECT slot_id, COUNT(*) AS n FROM benefit_slot_hold WHERE slot_id IN (?) AND expires_at > ? GROUP BY slot_id', [$ids, self::utc(new \DateTimeImmutable())], [\Doctrine\DBAL\ArrayParameterType::INTEGER, \PDO::PARAM_STR]) as $r) {
            $out[(int) $r['slot_id']]['held'] = (int) $r['n'];
        }

        return array_map(fn (array $t) => $t + ['booked' => 0, 'held' => 0], $out);
    }

    /** L'abonné choisit-il ses dates ? Créneaux au choix du client, ou choix à la souscription (puis comme un créneau au choix du client) */
    private static function clientChooses(PlanBenefit $pb, bool $atSubscription): bool
    {
        return $atSubscription ? $pb->getScheduling() === PlanBenefit::SCHEDULING_CLIENT
            : $pb->getScheduling() === PlanBenefit::SCHEDULING_CLIENT || ($pb->getScheduling() === PlanBenefit::SCHEDULING_SLOTS && $pb->getSlotChoice() === 'client');
    }

    private function planBenefitOf(SubscriptionPlan $plan, int $benefitId): ?PlanBenefit
    {
        foreach ($this->benefits->planBenefits()->forPlan($plan) as $pb) {
            if ($pb->getBenefit()?->getId() === $benefitId) {
                return $pb;
            }
        }

        return null;
    }

    /** Date libre de l'équipe : ATOM, ou « AAAA-MM-JJTHH:MM » à l'heure du magasin ; dans le futur @throws AboException 422 DATE_INVALID */
    private function parseStart(?string $text): \DateTimeImmutable
    {
        $text = trim((string) $text);
        $tz = $this->clock->timezone();
        $at = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $text) ?: (\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $text, $tz) ?: null);
        if ($at === null || $at <= new \DateTimeImmutable()) {
            throw new AboException(422, 'DATE_INVALID', 'Date attendue dans le futur (AAAA-MM-JJTHH:MM, heure du magasin).', 'startsAt');
        }

        return $at;
    }

    /** @throws AboException 422 DATE_INVALID */
    private function dateOrNull(mixed $value, string $field): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        $d = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $this->clock->timezone()) : false;
        if ($d === false || $d->format('Y-m-d') !== trim((string) $value)) {
            throw new AboException(422, 'DATE_INVALID', 'Date attendue (AAAA-MM-JJ).', $field);
        }

        return $d;
    }

    /**
     * Prénom et nom des intervenants (lot ABO-3b), pour l'équipe seulement
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function staffNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($v) => $v > 0)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->conn()->executeQuery('SELECT id, firstname, lastname FROM "user" WHERE id IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER])->fetchAllAssociative() as $u) {
            $name = trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? ''));
            $out[(int) $u['id']] = $name !== '' ? $name : sprintf('Compte n° %d', (int) $u['id']);
        }

        return $out;
    }

    /**
     * L'intervenant : un compte du site qui porte le rôle d'intervenant (lot ABO-3b ; les valeurs posées avant restent telles quelles)
     *
     * @throws AboException 422 SLOT_RULE_STAFF_INVALID
     */
    private function assertStaff(?int $userId): void
    {
        $user = $userId !== null ? $this->emProvider->getEntityManager()->getRepository(User::class)->find($userId) : null;
        if ($userId !== null && (!$user instanceof User || !in_array(\App\Security\TeamRoles::STAFF_PROVIDER, $user->getRoles(), true))) {
            throw new AboException(422, 'SLOT_RULE_STAFF_INVALID', 'Intervenant : un compte qui porte le rôle d\'intervenant (« Intervenants » du suivi des abonnements).', 'staffUserId');
        }
    }

    private function event(Subscription $subscription, string $type, ?int $benefitId, ?string $by, string $source, int $quantity = 1): void
    {
        $em = $this->emProvider->getEntityManager();
        $em->persist(new SubscriptionAboEvent($subscription, $type, $benefitId, $quantity, $source === BenefitOccurrence::SOURCE_CLIENT ? 'client' : null, $by));
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

        return SubscriptionLock::run($em->getConnection(), SubscriptionLock::key((string) $this->tenant->getTenantCode(), $subscription->getStripeSubscriptionId(), $subscription->getId()),
            function () use ($em, $subscription, $fn) {
                if ($em->contains($subscription)) {
                    $em->refresh($subscription); // statut changé pendant l'attente (pause, résiliation) : relu avant tout contrôle
                }

                return $fn();
            });
    }

    private function conn(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }

    private static function utc(\DateTimeInterface $at): string
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function at(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : \DateTimeImmutable::createFromInterface($at)->setTimezone($this->clock->timezone())->format(\DATE_ATOM);
    }
}
