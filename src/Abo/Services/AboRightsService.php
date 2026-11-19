<?php

namespace App\Abo\Services;

use App\Abo\Entity\Benefit;
use App\Abo\Entity\BenefitOccurrence;
use App\Abo\Entity\PlanBenefit;
use App\Abo\Entity\SubscriptionAboEvent;
use App\Entity\Subscription;
use App\Services\CaisseService\StoreClock;
use App\Services\Platform\OfferPayments\OfferPaymentsRepository;
use App\Services\SubscriptionService\SubscriptionLock;
use App\Services\SubscriptionService\SubscriptionService;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Droits par période des abonnements (lot ABO-1, 19/11/2026, contrat validé par le chef, `docs/plan-abo-universel.md`) :
 * - NAISSANCE à chaque facture payée (« période payée » de PROVISION-3, une fois par facture) : quantité de la formule × quantité de
 *   l'abonnement, valables jusqu'à la fin de la période ; aucune pour une facture de prorata ; un second essai gratuit du même client sur la
 *   même formule ne donne rien ; une prestation « sans limite » ne crée aucune ligne ;
 * - PASSAGE QUOTIDIEN : périodes payées non traitées réémises ; droit échu reporté UNE fois dans la limite de la formule si l'abonnement
 *   continue (jamais pour une carte de séances, ni après une résiliation), sinon perdu ;
 * - SOLDE, à faire, historique ; gestes de l'équipe (utiliser, annuler une utilisation sous 24 h, offrir, pause datée, reprise,
 *   résiliation en fin de période) et du client (pause, résiliation retenue par l'engagement minimal).
 * Tout geste sur un abonnement passe sous son verrou (SubscriptionLock).
 */
class AboRightsService
{
    public const TODO_DAYS = 7;
    public const UNDO_HOURS = 24;
    public const GIFT_MAX = 10;
    public const HISTORY_MAX = 50;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenant,
        private readonly BenefitService $benefits,
        private readonly SubscriptionService $subscriptions,
        private readonly OfferPaymentsRepository $payments,
        private readonly StoreClock $clock,
        private readonly LoggerInterface $logger,
        private readonly ?\App\Services\ContentAuditService\ContentAuditRecorder $audit = null,
    ) {
    }

    /**
     * Fait naître les droits d'une facture payée ; sans effet si la facture a déjà été traitée. Rend le nombre de droits créés.
     */
    public function birth(int $subscriptionId, string $invoiceId, ?int $periodStart, ?int $periodEnd, int $amountPaid, ?string $billingReason): int
    {
        if ($billingReason === 'subscription_update' && ($periodStart === null || $periodEnd === null)) {
            // prorata pur d'un changement de formule (aucune ligne d'abonnement hors prorata) : la nouvelle formule donne ses droits au cycle
            // suivant (Q2). Un changement qui ouvre une période (passage du mois à l'année : cycle remis à zéro, période payée) en donne.
            return 0;
        }
        $em = $this->emProvider->getEntityManager();
        $subscription = $em->getRepository(Subscription::class)->find($subscriptionId);
        $plan = $subscription?->getPlan();
        if ($subscription === null || $plan === null) {
            return 0;
        }
        $rows = array_values(array_filter($this->benefits->planBenefits()->forPlan($plan), fn (PlanBenefit $pb) => $pb->getQuantity() !== null && $pb->getBenefit() !== null));
        if ($rows === []) {
            return 0;
        }
        $trial = $amountPaid === 0 && $billingReason === 'subscription_create';
        if ($trial && $this->hadTrialBefore($subscription)) {
            // un second essai gratuit du même client sur la même formule ne donne aucun droit (Q1)
            $em->persist(new SubscriptionAboEvent($subscription, 'trial_without_rights'));
            $em->flush();

            return 0;
        }
        $start = $periodStart !== null ? (new \DateTimeImmutable())->setTimestamp($periodStart) : new \DateTimeImmutable();
        $end = $periodEnd !== null ? (new \DateTimeImmutable())->setTimestamp($periodEnd) : ($subscription->getCurrentPeriodEnd() ?? $start->modify('+1 month'));
        // une fin déjà passée (période d'une ligne mal choisie, facture réémise tard) ne fait jamais naître des droits échus
        if ($end <= new \DateTimeImmutable() && $subscription->getCurrentPeriodEnd() !== null && $subscription->getCurrentPeriodEnd() > $end) {
            $end = \DateTimeImmutable::createFromInterface($subscription->getCurrentPeriodEnd());
        }
        $conn = $em->getConnection();
        $created = 0;
        foreach ($rows as $pb) {
            $count = (int) $pb->getQuantity() * max(1, $subscription->getQuantity());
            for ($rank = 1; $rank <= $count; ++$rank) {
                // rejouer la facture ne crée rien : unicité (abonnement, ligne de formule, facture, rang)
                $created += $conn->executeStatement('INSERT INTO benefit_occurrence (subscription_id, plan_benefit_id, benefit_id, invoice_id, rank, origin, status, period_start, period_end, valid_until, carried, trial, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, false, ?, ?) ON CONFLICT (subscription_id, plan_benefit_id, invoice_id, rank) WHERE invoice_id IS NOT NULL DO NOTHING',
                    [$subscription->getId(), $pb->getId(), $pb->getBenefit()->getId(), $invoiceId, $rank, BenefitOccurrence::ORIGIN_PERIOD, BenefitOccurrence::STATUS_AVAILABLE,
                        $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $trial ? 't' : 'f', gmdate('Y-m-d H:i:s')]);
            }
        }
        if ($created > 0) {
            $em->persist(new SubscriptionAboEvent($subscription, 'born', null, $created));
            $em->flush();
        }

        return $created;
    }

    /**
     * Passage quotidien d'un site : réémission des périodes non traitées, puis report ou perte des droits échus.
     *
     * @return array{redispatched: int, carried: int, expired: int}
     */
    public function dailyPass(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $out = ['redispatched' => 0, 'carried' => 0, 'expired' => 0];
        $code = (string) $this->tenant->getTenantCode();
        foreach ($this->payments->undispatchedPeriods($code) as $p) {
            try {
                $this->birth($p['subscription_id'], $p['invoice_id'], $p['period_start'] !== null ? strtotime($p['period_start'] . ' UTC') : null,
                    $p['period_end'] !== null ? strtotime($p['period_end'] . ' UTC') : null, $p['amount_paid'], $p['billing_reason']);
                $this->payments->markPeriodDispatched($code, $p['invoice_id']);
                ++$out['redispatched'];
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('[Abo] période payée de l\'abonnement %d non traitée (%s)', $p['subscription_id'], $e::class));
            }
        }
        $em = $this->emProvider->getEntityManager();
        $groups = [];
        foreach ($em->getRepository(BenefitOccurrence::class)->dueAvailable($now) as $o) {
            $groups[(int) $o->getSubscription()->getId() . '|' . (int) $o->getPlanBenefit()?->getId() . '|' . (string) $o->getInvoiceId()][] = $o;
        }
        foreach ($groups as $group) {
            $subscription = $group[0]->getSubscription();
            $result = SubscriptionLock::run($em->getConnection(), SubscriptionLock::key($code, $subscription->getStripeSubscriptionId(), $subscription->getId()),
                fn () => $this->closeGroup($group, $now));
            $out['carried'] += $result[0];
            $out['expired'] += $result[1];
        }
        $em->flush();

        return $out;
    }

    /**
     * Le solde par prestation de la formule (côté client : seulement les nombres et les échéances).
     *
     * @return list<array{benefitId: int, name: string, kind: string, available: ?int, used: int, carried: int, unlimited: bool, nextExpiry: ?string}>
     */
    public function balance(Subscription $subscription, string $locale): array
    {
        $plan = $subscription->getPlan();
        if ($plan === null) {
            return [];
        }
        $now = new \DateTimeImmutable();
        $occ = $this->emProvider->getEntityManager()->getRepository(BenefitOccurrence::class)->forSubscription($subscription);
        $out = [];
        foreach ($this->benefits->planBenefits()->forPlan($plan) as $pb) {
            $b = $pb->getBenefit();
            if ($b === null) {
                continue;
            }
            $mine = array_filter($occ, fn (BenefitOccurrence $o) => $o->getBenefit()?->getId() === $b->getId());
            $avail = array_filter($mine, fn (BenefitOccurrence $o) => $o->getStatus() === BenefitOccurrence::STATUS_AVAILABLE && $o->getValidUntil() > $now);
            $next = null;
            foreach ($avail as $o) {
                $next = $next === null || $o->getValidUntil() < $next ? $o->getValidUntil() : $next;
            }
            $out[] = ['benefitId' => (int) $b->getId(), 'name' => $b->getName($locale), 'kind' => $b->getKind(),
                'available' => $pb->getQuantity() === null ? null : count($avail), 'used' => count(array_filter($mine, fn (BenefitOccurrence $o) => $o->getStatus() === BenefitOccurrence::STATUS_DONE)),
                'carried' => count(array_filter($avail, fn (BenefitOccurrence $o) => $o->isCarried())), 'unlimited' => $pb->getQuantity() === null, 'nextExpiry' => $this->at($next)];
        }
        // une prestation retirée de la formule : ses droits déjà payés restent au solde jusqu'à leur échéance
        $listed = array_column($out, 'benefitId');
        $left = [];
        foreach ($occ as $o) {
            $b = $o->getBenefit();
            if ($b !== null && !in_array((int) $b->getId(), $listed, true) && $o->getStatus() === BenefitOccurrence::STATUS_AVAILABLE && $o->getValidUntil() > $now) {
                $left[(int) $b->getId()][] = $o;
            }
        }
        foreach ($left as $benefitId => $avail) {
            $b = $avail[0]->getBenefit();
            $next = min(array_map(fn (BenefitOccurrence $o) => $o->getValidUntil(), $avail));
            $out[] = ['benefitId' => $benefitId, 'name' => $b->getName($locale), 'kind' => $b->getKind(), 'available' => count($avail), 'used' => 0,
                'carried' => count(array_filter($avail, fn (BenefitOccurrence $o) => $o->isCarried())), 'unlimited' => false, 'nextExpiry' => $this->at($next)];
        }

        return $out;
    }

    /** @return list<array{benefitId: int, name: string, count: int, validUntil: string}> les droits disponibles qui expirent sous 7 jours */
    public function todo(Subscription $subscription, string $locale): array
    {
        $now = new \DateTimeImmutable();
        $limit = $now->modify('+' . self::TODO_DAYS . ' days');
        $out = [];
        foreach ($this->emProvider->getEntityManager()->getRepository(BenefitOccurrence::class)->forSubscription($subscription) as $o) {
            if ($o->getStatus() !== BenefitOccurrence::STATUS_AVAILABLE || $o->getValidUntil() <= $now || $o->getValidUntil() > $limit) {
                continue;
            }
            $key = $o->getBenefit()->getId() . '|' . $o->getValidUntil()->getTimestamp();
            $out[$key] ??= ['benefitId' => (int) $o->getBenefit()->getId(), 'name' => $o->getBenefit()->getName($locale), 'count' => 0, 'validUntil' => (string) $this->at($o->getValidUntil())];
            ++$out[$key]['count'];
        }

        return array_values($out);
    }

    /** @return list<array<string, mixed>> les 50 derniers mouvements ; « par qui » et la note seulement pour l'équipe */
    public function history(Subscription $subscription, bool $forTeam): array
    {
        $rows = $this->emProvider->getEntityManager()->getRepository(SubscriptionAboEvent::class)->findBy(['subscription' => $subscription], ['at' => 'DESC', 'id' => 'DESC'], self::HISTORY_MAX);

        return array_map(fn (SubscriptionAboEvent $e) => ['type' => $e->getType(), 'benefitId' => $e->getBenefitId(), 'quantity' => $e->getQuantity(), 'at' => $this->at($e->getAt())]
            + ($forTeam ? ['by' => $e->getByUser(), 'note' => $e->getNote()] : []), $rows);
    }

    /**
     * Marque utilisé le droit disponible qui expire le premier (sans date ni preuve : ABO-3 apportera « Fait » complet).
     *
     * @return array{occurrenceId: int, benefitId: int, undoUntil: string}
     * @throws AboException 404 BENEFIT_NOT_IN_PLAN, 409 BALANCE_EMPTY
     */
    public function use(Subscription $subscription, int $benefitId, ?string $note, ?string $by): array
    {
        return $this->locked($subscription, function () use ($subscription, $benefitId, $note, $by) {
            $em = $this->emProvider->getEntityManager();
            $conn = $em->getConnection();
            $now = gmdate('Y-m-d H:i:s');
            // une prestation retirée de la formule : ses droits déjà payés restent utilisables jusqu'à leur échéance
            $pb = $this->planBenefitOrNull($subscription, $benefitId);
            if ($pb === null && $conn->fetchOne("SELECT 1 FROM benefit_occurrence WHERE subscription_id = ? AND benefit_id = ? AND status = 'available' AND valid_until > ? LIMIT 1", [$subscription->getId(), $benefitId, $now]) === false) {
                throw new AboException(404, 'BENEFIT_NOT_IN_PLAN', 'Cette prestation n\'est pas dans la formule de l\'abonnement.', 'benefitId');
            }
            $note = $note !== null ? mb_substr(trim(strip_tags($note)), 0, 300) : null;
            if ($pb !== null && $pb->getQuantity() === null) {
                // sans limite : l'utilisation est notée, sans solde à décompter
                $id = (int) $conn->fetchOne('INSERT INTO benefit_occurrence (subscription_id, plan_benefit_id, benefit_id, rank, origin, status, valid_until, done_at, done_by, note, created_at)
                    VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?) RETURNING id', [$subscription->getId(), $pb->getId(), $benefitId, BenefitOccurrence::ORIGIN_PERIOD, BenefitOccurrence::STATUS_DONE, $now, $now, $by, $note !== '' ? $note : null, $now]);
            } else {
                // deux gestes simultanés ne prennent jamais le même droit : ligne verrouillée, les autres sautées
                $id = (int) $conn->fetchOne("UPDATE benefit_occurrence SET status = 'done', done_at = ?, done_by = ?, note = ? WHERE id = (
                        SELECT id FROM benefit_occurrence WHERE subscription_id = ? AND benefit_id = ? AND status = 'available' AND valid_until > ?
                        ORDER BY valid_until, id LIMIT 1 FOR UPDATE SKIP LOCKED) RETURNING id", [$now, $by, $note !== '' ? $note : null, $subscription->getId(), $benefitId, $now]);
                if ($id === 0) {
                    throw new AboException(409, 'BALANCE_EMPTY', 'Plus aucun droit disponible pour cette prestation.', 'benefitId');
                }
            }
            $em->persist(new SubscriptionAboEvent($subscription, 'used', $benefitId, 1, $note, $by));
            $em->flush();

            return ['occurrenceId' => $id, 'benefitId' => $benefitId, 'undoUntil' => (string) $this->at((new \DateTimeImmutable())->modify('+' . self::UNDO_HOURS . ' hours'))];
        });
    }

    /** @throws AboException 404 OCCURRENCE_NOT_FOUND, 409 USE_UNDO_EXPIRED */
    public function undoUse(Subscription $subscription, int $occurrenceId, ?string $by): void
    {
        $this->locked($subscription, function () use ($subscription, $occurrenceId, $by) {
            $em = $this->emProvider->getEntityManager();
            $o = $em->getRepository(BenefitOccurrence::class)->find($occurrenceId);
            if ($o === null || $o->getSubscription()?->getId() !== $subscription->getId() || $o->getStatus() !== BenefitOccurrence::STATUS_DONE) {
                throw new AboException(404, 'OCCURRENCE_NOT_FOUND', 'Utilisation introuvable.');
            }
            if ($o->getDoneAt() === null || $o->getDoneAt() < (new \DateTimeImmutable())->modify('-' . self::UNDO_HOURS . ' hours')) {
                throw new AboException(409, 'USE_UNDO_EXPIRED', 'Une utilisation s\'annule dans les 24 heures.');
            }
            $benefitId = (int) $o->getBenefit()->getId();
            if ($o->getInvoiceId() === null && $o->getOrigin() === BenefitOccurrence::ORIGIN_PERIOD) {
                $em->remove($o); // utilisation d'une prestation sans limite : la trace disparaît
            } else {
                $o->setStatus($o->getValidUntil() > new \DateTimeImmutable() ? BenefitOccurrence::STATUS_AVAILABLE : BenefitOccurrence::STATUS_EXPIRED)->setDoneAt(null)->setDoneBy(null)->setNote(null);
            }
            $em->persist(new SubscriptionAboEvent($subscription, 'use_undone', $benefitId, 1, null, $by));
            $em->flush();
        });
    }

    /**
     * Offre des droits (geste commercial), valables jusqu'à la fin de la période en cours ; motif obligatoire, au journal du site.
     *
     * @throws AboException 422 GIFT_INVALID, 404 BENEFIT_NOT_IN_PLAN
     */
    public function gift(Subscription $subscription, int $benefitId, int $quantity, ?string $reason, ?string $by): int
    {
        $reason = trim(strip_tags((string) $reason));
        if ($quantity < 1 || $quantity > self::GIFT_MAX || $reason === '') {
            throw new AboException(422, 'GIFT_INVALID', sprintf('De 1 à %d droits offerts, avec un motif.', self::GIFT_MAX), $reason === '' ? 'reason' : 'quantity');
        }

        return $this->locked($subscription, function () use ($subscription, $benefitId, $quantity, $reason, $by) {
            $pb = $this->planBenefit($subscription, $benefitId);
            if ($pb->getQuantity() === null) {
                throw new AboException(422, 'GIFT_INVALID', 'Cette prestation est déjà sans limite.', 'benefitId');
            }
            $em = $this->emProvider->getEntityManager();
            $now = new \DateTimeImmutable();
            $until = $subscription->getCurrentPeriodEnd() !== null && $subscription->getCurrentPeriodEnd() > $now ? $subscription->getCurrentPeriodEnd() : $now->modify('+30 days');
            for ($i = 1; $i <= $quantity; ++$i) {
                $em->persist((new BenefitOccurrence())->setSubscription($subscription)->setPlanBenefit($pb)->setBenefit($pb->getBenefit())->setRank($i)
                    ->setOrigin(BenefitOccurrence::ORIGIN_GIFT)->setStatus(BenefitOccurrence::STATUS_AVAILABLE)->setValidUntil($until)->setReason($reason));
            }
            $em->persist(new SubscriptionAboEvent($subscription, 'gift', $benefitId, $quantity, $reason, $by));
            $em->flush();
            $this->journal($subscription, 'gift', ['benefitId' => $benefitId, 'quantity' => $quantity, 'reason' => $reason]);

            return $quantity;
        });
    }

    /**
     * Pause datée (décision 3) : dans la limite de la formule (90 jours, une fois par an glissant), Stripe reprend seul à la date ; les droits
     * disponibles sont prolongés de la durée de la pause, sans perte.
     *
     * @throws AboException 422 PAUSE_DATE_INVALID | PAUSE_TOO_LONG | PAUSE_LIMIT_REACHED, 409 SUBSCRIPTION_NOT_ACTIVE
     */
    public function pause(Subscription $subscription, ?string $untilDate, ?string $by): Subscription
    {
        $this->pauseUntil($subscription, $untilDate); // refus rapide, sans attendre le verrou

        return $this->locked($subscription, function () use ($subscription, $untilDate, $by) {
            // relu sous le verrou (la fiche vient d'être relue) : deux pauses simultanées ne prolongent jamais deux fois
            ['until' => $until, 'days' => $days] = $this->pauseUntil($subscription, $untilDate);
            $now = new \DateTimeImmutable();
            $seconds = $until->getTimestamp() - $now->getTimestamp();
            $this->subscriptions->pause($subscription, $until);
            $em = $this->emProvider->getEntityManager();
            $pauses = $subscription->getPauses();
            $pauses[] = ['from' => $now->format(\DATE_ATOM), 'until' => $until->format(\DATE_ATOM), 'by' => $by, 'extendedSeconds' => $seconds];
            $subscription->setPauses($pauses);
            // TOUS les droits disponibles sont prolongés de la durée de la pause, y compris un droit échu que le passage n'a pas encore traité :
            // rien n'est perdu pendant qu'on ne paie pas
            $em->getConnection()->executeStatement("UPDATE benefit_occurrence SET valid_until = valid_until + (? || ' seconds')::interval WHERE subscription_id = ? AND status = 'available'",
                [(string) $seconds, $subscription->getId()]);
            $em->persist(new SubscriptionAboEvent($subscription, 'pause', null, $days, null, $by));
            $em->flush();
            $em->refresh($subscription);

            return $subscription;
        });
    }

    /**
     * La date de reprise d'une pause, vérifiée sans rien écrire (aussi pour l'aperçu de l'assistant) : sans date, la durée maximale de la
     * formule ; dans la limite de la formule (jours, pauses par an glissant) ; abonnement actif ou en essai.
     *
     * @return array{until: \DateTimeImmutable, days: int}
     * @throws AboException 422 PAUSE_DATE_INVALID | PAUSE_TOO_LONG | PAUSE_LIMIT_REACHED, 409 SUBSCRIPTION_NOT_ACTIVE
     */
    public function pauseUntil(Subscription $subscription, ?string $untilDate): array
    {
        if (!in_array($subscription->getStatus(), [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING], true)) {
            throw new AboException(409, 'SUBSCRIPTION_NOT_ACTIVE', 'Seul un abonnement actif peut être mis en pause.');
        }
        $plan = $subscription->getPlan();
        $tz = $this->clock->timezone();
        $now = new \DateTimeImmutable();
        if ($untilDate === null || trim($untilDate) === '') {
            $until = $now->setTimezone($tz)->modify('+' . (int) $plan?->getMaxPauseDays() . ' days')->setTime(0, 0);
        } else {
            $until = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($untilDate), $tz) ?: null;
            if ($until === null || $until->format('Y-m-d') !== trim($untilDate)) {
                throw new AboException(422, 'PAUSE_DATE_INVALID', 'Date de reprise attendue (AAAA-MM-JJ).', 'until');
            }
        }
        if ($until <= $now) {
            throw new AboException(422, 'PAUSE_DATE_INVALID', 'La reprise doit être dans le futur.', 'until');
        }
        $days = (int) ceil(($until->getTimestamp() - $now->getTimestamp()) / 86400);
        if ($plan !== null && $days > $plan->getMaxPauseDays()) {
            throw new AboException(422, 'PAUSE_TOO_LONG', sprintf('Une pause dure %d jours au plus.', $plan->getMaxPauseDays()), 'until');
        }
        $yearAgo = $now->modify('-365 days');
        $recent = array_filter($subscription->getPauses(), fn (array $p) => isset($p['from']) && new \DateTimeImmutable($p['from']) > $yearAgo);
        if ($plan !== null && count($recent) >= $plan->getPausesPerYear()) {
            throw new AboException(422, 'PAUSE_LIMIT_REACHED', sprintf('%d pause(s) par an au plus.', $plan->getPausesPerYear()), 'until');
        }

        return ['until' => $until, 'days' => $days];
    }

    /**
     * Reprise. L'équipe et l'assistant (`$byTeam`) reprennent une PAUSE, rien d'autre : une résiliation programmée (carte de séances, motif
     * de l'équipe) reste programmée ; refus 409 SUBSCRIPTION_NOT_PAUSED hors pause. Le client garde le sens documenté de sa route (la
     * reprise lève aussi une résiliation programmée), sauf pour une carte de séances, payée une fois. Une reprise avant la date prévue
     * retire aux droits la part de prolongation non consommée.
     *
     * @throws AboException 409 SUBSCRIPTION_NOT_PAUSED
     */
    public function resume(Subscription $subscription, ?string $by, bool $byTeam = true): Subscription
    {
        return $this->locked($subscription, function () use ($subscription, $by, $byTeam) {
            if ($byTeam && $subscription->getStatus() !== Subscription::STATUS_PAUSED) {
                throw new AboException(409, 'SUBSCRIPTION_NOT_PAUSED', 'Cet abonnement n\'est pas en pause.');
            }
            $now = new \DateTimeImmutable();
            $this->subscriptions->resume($subscription, !$byTeam && !($subscription->getPlan()?->isSinglePeriod() ?? false));
            $em = $this->emProvider->getEntityManager();
            $pauses = $subscription->getPauses();
            $last = count($pauses) - 1;
            if ($last >= 0 && !isset($pauses[$last]['resumedAt'])) {
                $pauses[$last]['resumedAt'] = $now->format(\DATE_ATOM);
                $unused = isset($pauses[$last]['until'], $pauses[$last]['extendedSeconds']) ? min((int) $pauses[$last]['extendedSeconds'], (new \DateTimeImmutable($pauses[$last]['until']))->getTimestamp() - $now->getTimestamp()) : 0;
                if ($unused > 0) {
                    // reprise anticipée : la prolongation non consommée est retirée, sans jamais rendre échu un droit encore valable
                    // (seuls les droits nés avant la pause, ou dans sa seconde, ont été prolongés : un droit offert pendant la pause garde sa date)
                    $em->getConnection()->executeStatement("UPDATE benefit_occurrence SET valid_until = GREATEST(valid_until - (? || ' seconds')::interval, ?) WHERE subscription_id = ? AND status = 'available' AND created_at <= ?",
                        [(string) $unused, gmdate('Y-m-d H:i:s', $now->getTimestamp()), $subscription->getId(), gmdate('Y-m-d H:i:s', (new \DateTimeImmutable((string) $pauses[$last]['from']))->getTimestamp())]);
                    $pauses[$last]['returnedSeconds'] = $unused;
                }
                $subscription->setPauses($pauses);
            }
            $em->persist(new SubscriptionAboEvent($subscription, 'resume', null, 0, null, $by));
            $em->flush();

            return $subscription;
        });
    }

    /**
     * Résiliation EN FIN DE PÉRIODE. Engagement minimal (décision 4) : le client est retenu avant N périodes payées ; l'équipe peut résilier
     * quand même, motif obligatoire, au journal du site (Q3).
     *
     * @throws AboException 409 SUBSCRIPTION_MINIMUM_TERM (client), 422 REASON_REQUIRED (équipe)
     */
    public function cancelAtPeriodEnd(Subscription $subscription, bool $byTeam, ?string $reason, ?string $by): Subscription
    {
        $reason = trim(strip_tags((string) $reason));
        $block = $this->minimumTermBlock($subscription);
        if ($block !== null) {
            if (!$byTeam) {
                throw $block;
            }
            if ($reason === '') {
                throw new AboException(422, 'REASON_REQUIRED', 'Pendant l\'engagement minimal, un motif est demandé.', 'reason');
            }
        }

        return $this->locked($subscription, function () use ($subscription, $reason, $by, $byTeam) {
            $this->subscriptions->cancel($subscription, true);
            $em = $this->emProvider->getEntityManager();
            $em->persist(new SubscriptionAboEvent($subscription, 'cancel', null, 0, $reason !== '' ? $reason : null, $by));
            $em->flush();
            if ($byTeam) {
                $this->journal($subscription, 'cancel_at_period_end', ['reason' => $reason !== '' ? $reason : null]);
            }

            return $subscription;
        });
    }

    /**
     * L'engagement minimal (décision 4) retient-il encore l'abonné ? Le refus à lever, avec la date à partir de laquelle il pourra résilier.
     */
    public function minimumTermBlock(Subscription $subscription): ?AboException
    {
        [$blocked, $from] = $this->termEnd($subscription);
        if (!$blocked) {
            return null;
        }
        $terms = (int) $subscription->getPlan()?->getMinimumTerms();

        return new AboException(409, 'SUBSCRIPTION_MINIMUM_TERM', sprintf('Engagement de %d période(s) : résiliation possible à partir du %s.', $terms,
            $from?->setTimezone($this->clock->timezone())->format('d/m/Y') ?? '?'), null, ['cancellableFrom' => $this->at($from)]);
    }

    /**
     * L'engagement minimal court-il encore, et jusqu'à quand ? La résiliation prend effet à une fin de période : celle qui suit la N-ième
     * période payée (null : date inconnue, abonnement sans fin de période connue).
     *
     * @return array{0: bool, 1: ?\DateTimeImmutable}
     */
    public function termEnd(Subscription $subscription): array
    {
        $plan = $subscription->getPlan();
        $terms = (int) $plan?->getMinimumTerms();
        if ($terms <= 0) {
            return [false, null];
        }
        $paid = $this->payments->paidPeriodsCount((string) $this->tenant->getTenantCode(), (int) $subscription->getId());
        if ($paid >= $terms) {
            return [false, null];
        }
        $from = $subscription->getCurrentPeriodEnd() !== null ? \DateTimeImmutable::createFromInterface($subscription->getCurrentPeriodEnd()) : null;
        $missing = max(0, $terms - $paid - 1);
        if ($from !== null && $missing > 0) {
            $from = $from->modify(sprintf('+%d %s', $missing * max(1, (int) $plan?->getIntervalCount()), $plan?->getInterval() ?? 'month'));
        }

        return [true, $from];
    }

    /**
     * Le CLIENT résilie pendant son engagement (décision du chef du 19/11/2026, résiliation simple) : la résiliation est PROGRAMMÉE à la fin
     * de l'engagement (Stripe `cancel_at`), jamais refusée. Sans date connue, le refus d'engagement est rendu (409 SUBSCRIPTION_MINIMUM_TERM).
     *
     * @throws AboException 409 SUBSCRIPTION_MINIMUM_TERM
     */
    public function cancelAtTermEnd(Subscription $subscription): Subscription
    {
        return $this->locked($subscription, function () use ($subscription) {
            [$blocked, $from] = $this->termEnd($subscription);
            if (!$blocked) {
                return $this->subscriptions->cancel($subscription, true);
            }
            if ($from === null) {
                throw $this->minimumTermBlock($subscription);
            }
            $this->subscriptions->cancelAt($subscription, $from);
            $em = $this->emProvider->getEntityManager();
            $em->persist(new SubscriptionAboEvent($subscription, 'cancel', null, 0, null, null));
            $em->flush();

            return $subscription;
        });
    }

    /** @param list<BenefitOccurrence> $group droits échus d'une même facture et d'une même ligne @return array{0: int, 1: int} [reportés, perdus] */
    private function closeGroup(array $group, \DateTimeImmutable $now): array
    {
        $em = $this->emProvider->getEntityManager();
        $first = $group[0];
        $subscription = $first->getSubscription();
        $em->refresh($subscription);
        $plan = $subscription->getPlan();
        $pb = $first->getPlanBenefit();
        $continues = in_array($subscription->getStatus(), [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING, Subscription::STATUS_PAST_DUE, Subscription::STATUS_PAUSED], true)
            && !$subscription->isCancelAtPeriodEnd() && !($plan?->isSinglePeriod() ?? false);
        $allowed = 0;
        // un droit d'ESSAI n'est jamais reporté : l'essai donne ses droits une fois (Q1), pas un supplément sur la première période payée
        if ($continues && $pb !== null && $first->getOrigin() === BenefitOccurrence::ORIGIN_PERIOD && $first->getInvoiceId() !== null && !$first->isTrial()) {
            $already = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM benefit_occurrence WHERE subscription_id = ? AND plan_benefit_id = ? AND invoice_id = ? AND carried', [$subscription->getId(), $pb->getId(), $first->getInvoiceId()]);
            // la limite de report est celle d'UNE place : un abonnement à 3 places en reporte trois fois plus
            $allowed = max(0, (int) $pb->getEffectiveCarryOver() * max(1, $subscription->getQuantity()) - $already);
        }
        $carried = $expired = 0;
        foreach ($group as $o) {
            $em->refresh($o);
            if ($o->getStatus() !== BenefitOccurrence::STATUS_AVAILABLE || $o->getValidUntil() > $now) {
                continue; // utilisé ou prolongé entre-temps
            }
            if ($allowed > 0 && !$o->isCarried()) {
                $length = $o->getPeriodStart() !== null && $o->getPeriodEnd() !== null ? max(86400, $o->getPeriodEnd()->getTimestamp() - $o->getPeriodStart()->getTimestamp()) : 30 * 86400;
                $o->setCarried(true)->setValidUntil($o->getValidUntil()->modify('+' . $length . ' seconds'));
                --$allowed;
                ++$carried;
            } else {
                $o->setStatus(BenefitOccurrence::STATUS_EXPIRED);
                ++$expired;
            }
        }
        $benefitId = (int) $first->getBenefit()->getId();
        if ($carried > 0) {
            $em->persist(new SubscriptionAboEvent($subscription, 'carried', $benefitId, $carried));
        }
        if ($expired > 0) {
            $em->persist(new SubscriptionAboEvent($subscription, 'expired', $benefitId, $expired));
        }
        $em->flush();

        return [$carried, $expired];
    }

    private function hadTrialBefore(Subscription $subscription): bool
    {
        return (int) $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM benefit_occurrence o JOIN subscription s ON s.id = o.subscription_id
            WHERE s.user_id = ? AND s.plan_id = ? AND s.id <> ? AND o.trial', [$subscription->getUser()?->getId(), $subscription->getPlan()?->getId(), $subscription->getId()]) > 0;
    }

    /** @throws AboException 404 BENEFIT_NOT_IN_PLAN */
    private function planBenefit(Subscription $subscription, int $benefitId): PlanBenefit
    {
        return $this->planBenefitOrNull($subscription, $benefitId)
            ?? throw new AboException(404, 'BENEFIT_NOT_IN_PLAN', 'Cette prestation n\'est pas dans la formule de l\'abonnement.', 'benefitId');
    }

    private function planBenefitOrNull(Subscription $subscription, int $benefitId): ?PlanBenefit
    {
        foreach ($subscription->getPlan() !== null ? $this->benefits->planBenefits()->forPlan($subscription->getPlan()) : [] as $pb) {
            if ($pb->getBenefit()?->getId() === $benefitId) {
                return $pb;
            }
        }

        return null;
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
                // la fiche a pu changer pendant l'attente du verrou (autre geste, webhook) : elle est relue avant tout contrôle et toute écriture
                if ($em->contains($subscription)) {
                    $em->refresh($subscription);
                }

                return $fn();
            });
    }

    private function journal(Subscription $subscription, string $action, array $details): void
    {
        $this->audit?->record('subscription', (string) $subscription->getId(), $action, null, $details, ['abonnements']);
    }

    /** Date ATOM à l'heure du magasin (aussi pour l'assistant) */
    public function at(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : \DateTimeImmutable::createFromInterface($at)->setTimezone($this->clock->timezone())->format(\DATE_ATOM);
    }
}
