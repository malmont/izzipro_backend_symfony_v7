<?php

namespace App\Abo\Services;

use App\Abo\Entity\BenefitOccurrence;
use App\Abo\Event\SlotFreedEvent;
use App\Entity\Subscription;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\MediaUrlResolver;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Liste d'attente d'un créneau complet (lot ABO-4, 21/11/2026, contrat validé par le chef, `docs/boutique.md` § « Liste d'attente, rappels
 * et calendrier »).
 *
 * - L'abonné s'inscrit sur un créneau complet avec un droit à planifier valable au début du créneau (3 attentes ouvertes au plus), en sort.
 * - Une place libérée est OFFERTE à la première personne : elle compte dans la capacité, sous le verrou du créneau, et court 2 h ou jusqu'à
 *   1 h avant la séance ; plus de promotion automatique à moins d'1 h. L'abonné l'accepte : la date est posée comme un rendez-vous.
 * - Ce qui déclenche une promotion (décision du chef) : les gestes d'écriture (annulation, déplacement, place retenue rendue, pause,
 *   capacité changée, inscription, sortie, acceptation, proposition de l'équipe) et le passage horaire `app:abo:periodes`. AUCUNE lecture :
 *   une offre échue se montre échue, sans rien écrire ni envoyer.
 * - Le courriel de l'offre est INDISPENSABLE (catalogue NOTIF, décision du chef), dans la langue de l'abonné, avec l'heure limite à l'heure
 *   du magasin.
 */
class AboWaitlistService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly AboAgendaService $agenda,
        private readonly StoreClock $clock,
        private readonly LoggerInterface $logger,
        private readonly ?EmailSenderService $sender = null,
        private readonly ?EmailLocale $locale = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?MediaUrlResolver $urls = null,
        private readonly ?\App\Services\ContentAuditService\ContentAuditRecorder $audit = null,
    ) {
    }

    // ---- abonné ---------------------------------------------------------------------------------------------------------------------------

    /**
     * Inscrit l'abonné sur la liste d'attente d'un créneau complet.
     *
     * @return array<string, mixed> l'attente
     * @throws AboException 409 SLOT_NOT_FULL | ALREADY_WAITING | SLOT_ALREADY_BOOKED | WAITLIST_LIMIT | BALANCE_EMPTY | SLOT_AFTER_VALIDITY
     */
    public function join(Subscription $subscription, int $slotId, string $host = ''): array
    {
        $offers = [];
        $id = $this->agenda->withSubscriptionLock($subscription, fn () => $this->conn()->transactional(function (Connection $conn) use ($subscription, $slotId, &$offers) {
            $slot = $this->agenda->clientSlotIn($conn, $subscription, $slotId);
            $subscriptionId = (int) $subscription->getId();
            if ($conn->fetchOne("SELECT 1 FROM benefit_occurrence WHERE subscription_id = ? AND slot_id = ? AND status = 'scheduled' LIMIT 1", [$subscriptionId, $slotId]) !== false) {
                throw new AboException(409, 'SLOT_ALREADY_BOOKED', 'Cet abonnement a déjà une place sur ce créneau.', 'slotId');
            }
            AboWaitlist::closeStale($conn, $subscriptionId);
            if ($conn->fetchOne("SELECT 1 FROM benefit_waitlist WHERE slot_id = ? AND subscription_id = ? AND status IN ('waiting', 'offered')", [$slotId, $subscriptionId]) !== false) {
                throw new AboException(409, 'ALREADY_WAITING', 'Vous êtes déjà sur la liste d\'attente de ce créneau.', 'slotId');
            }
            // un geste d'écriture : les offres échues de ce créneau passent d'abord à la suite
            $offers = $this->promoteIn($conn, $slotId);
            if ($this->agenda->freePlaces($conn, $slotId) > 0 && !$this->hasWaiting($conn, $slotId)) {
                throw new AboException(409, 'SLOT_NOT_FULL', 'Une place est libre sur ce créneau : réservez-la directement.', 'slotId');
            }
            if ((int) $conn->fetchOne("SELECT COUNT(*) FROM benefit_waitlist WHERE subscription_id = ? AND status IN ('waiting', 'offered')", [$subscriptionId]) >= AboWaitlist::MAX_OPEN) {
                throw new AboException(409, 'WAITLIST_LIMIT', sprintf('%d listes d\'attente au plus en même temps.', AboWaitlist::MAX_OPEN), 'slotId');
            }
            $occurrence = $this->carriedRight($conn, $subscriptionId, (int) $slot['benefit_id'], (string) $slot['starts_at']);

            return (int) $conn->fetchOne("INSERT INTO benefit_waitlist (slot_id, subscription_id, benefit_id, occurrence_id, status, created_at) VALUES (?, ?, ?, ?, 'waiting', ?) RETURNING id",
                [$slotId, $subscriptionId, (int) $slot['benefit_id'], $occurrence, AboWaitlist::utc(new \DateTimeImmutable())]);
        }));
        $this->sendOffers($offers, $host);

        return $this->view($this->row($id), false);
    }

    /**
     * L'abonné sort de la liste d'attente (une place qui lui était offerte passe à la suite).
     *
     * @throws AboException 404 WAITLIST_NOT_FOUND
     */
    public function leave(Subscription $subscription, int $slotId, string $host = ''): void
    {
        $offers = [];
        $this->conn()->transactional(function (Connection $conn) use ($subscription, $slotId, &$offers) {
            $this->agenda->lockSlot($conn, $slotId);
            $n = $conn->executeStatement("UPDATE benefit_waitlist SET status = 'left', closed_at = ?, closed_reason = 'customer' WHERE slot_id = ? AND subscription_id = ? AND status IN ('waiting', 'offered')",
                [AboWaitlist::utc(new \DateTimeImmutable()), $slotId, $subscription->getId()]);
            if ($n === 0) {
                throw new AboException(404, 'WAITLIST_NOT_FOUND', 'Vous n\'êtes pas sur la liste d\'attente de ce créneau.', 'slotId');
            }
            $offers = $this->promoteIn($conn, $slotId);
        });
        $this->sendOffers($offers, $host);
    }

    /**
     * L'abonné accepte la place offerte : la date est posée comme un rendez-vous (son droit porté, sinon un autre valable).
     *
     * @return array<string, mixed> le rendez-vous
     * @throws AboException 404 WAITLIST_NOT_FOUND, 409 OFFER_EXPIRED
     */
    public function accept(Subscription $subscription, int $waitId, string $host = ''): array
    {
        $offers = [];
        $expired = false;
        $appointment = $this->agenda->withSubscriptionLock($subscription, fn () => $this->conn()->transactional(function (Connection $conn) use ($subscription, $waitId, &$offers, &$expired) {
            $slotId = $conn->fetchOne('SELECT slot_id FROM benefit_waitlist WHERE id = ? AND subscription_id = ?', [$waitId, $subscription->getId()]);
            if ($slotId === false || $slotId === null) {
                throw new AboException(404, 'WAITLIST_NOT_FOUND', 'Attente introuvable.', 'waitId');
            }
            $this->agenda->lockSlot($conn, (int) $slotId);
            $w = $conn->fetchAssociative('SELECT * FROM benefit_waitlist WHERE id = ? FOR UPDATE', [$waitId]);
            if ($w === false || $w['status'] !== AboWaitlist::OFFERED) {
                throw new AboException(404, 'WAITLIST_NOT_FOUND', 'Aucune place ne vous est offerte sur ce créneau.', 'waitId');
            }
            $now = new \DateTimeImmutable();
            if ((string) $w['offer_expires_at'] <= AboWaitlist::utc($now)) {
                $expired = true;
                $offers = $this->promoteIn($conn, (int) $slotId); // l'offre échue passe à la suite (geste d'écriture)

                return null;
            }
            $conn->executeStatement("UPDATE benefit_waitlist SET status = 'accepted', closed_at = ?, closed_reason = 'accepted' WHERE id = ?", [AboWaitlist::utc($now), $waitId]);
            $carried = $w['occurrence_id'] !== null && $conn->fetchOne("SELECT 1 FROM benefit_occurrence WHERE id = ? AND status = 'available'", [(int) $w['occurrence_id']]) !== false
                ? (int) $w['occurrence_id'] : null;
            $this->agenda->clientSlotIn($conn, $subscription, (int) $slotId);

            return $this->agenda->bookAcceptedIn($conn, $subscription, (int) $w['benefit_id'], $carried, (int) $slotId);
        }));
        $this->sendOffers($offers, $host);
        if ($expired) {
            throw new AboException(409, 'OFFER_EXPIRED', 'Le délai pour confirmer cette place est passé : elle a été proposée à la personne suivante.', 'waitId');
        }

        return $appointment;
    }

    /** Les attentes ouvertes d'un abonnement (lecture seule : une offre échue se montre échue) @return list<array<string, mixed>> */
    public function mine(Subscription $subscription): array
    {
        $rows = $this->conn()->fetchAllAssociative("SELECT * FROM benefit_waitlist WHERE subscription_id = ? AND status IN ('waiting', 'offered') ORDER BY id", [$subscription->getId()]);

        return array_map(fn (array $r) => $this->view($r, false), $rows);
    }

    // ---- équipe ---------------------------------------------------------------------------------------------------------------------------

    /**
     * La liste d'attente d'un créneau pour l'équipe (lecture seule) : rang, numéro d'abonnement, prénom (`$withIdentity`), état, échéance.
     *
     * @return array{slotId: int, freePlaces: int, waitlist: list<array<string, mixed>>}
     */
    public function forSlot(int $slotId, bool $withIdentity): array
    {
        $conn = $this->conn();
        if ($conn->fetchOne('SELECT 1 FROM benefit_slot WHERE id = ?', [$slotId]) === false) {
            throw new AboException(404, 'SLOT_NOT_FOUND', 'Créneau introuvable.', 'slotId');
        }
        $rows = $conn->fetchAllAssociative("SELECT w.*, u.firstname FROM benefit_waitlist w JOIN subscription s ON s.id = w.subscription_id LEFT JOIN \"user\" u ON u.id = s.user_id
            WHERE w.slot_id = ? AND w.status IN ('waiting', 'offered') ORDER BY w.id", [$slotId]);
        $out = [];
        foreach ($rows as $r) {
            $view = $this->view($r, true);
            if ($withIdentity) {
                $view['customer'] = ['firstname' => $r['firstname']];
            }
            $out[] = $view;
        }

        return ['slotId' => $slotId, 'freePlaces' => $this->freePlacesNow($slotId), 'waitlist' => $out];
    }

    /**
     * L'équipe propose la place à une personne de la liste (même à moins d'1 h du début) : il faut une place libre.
     *
     * @return array<string, mixed> l'attente offerte
     * @throws AboException 404 WAITLIST_NOT_FOUND, 409 SLOT_FULL
     */
    public function propose(int $waitId, ?string $by, string $host = ''): array
    {
        $slotId = $this->conn()->fetchOne('SELECT slot_id FROM benefit_waitlist WHERE id = ?', [$waitId]);
        if ($slotId === false || $slotId === null) {
            throw new AboException(404, 'WAITLIST_NOT_FOUND', 'Attente introuvable.', 'waitId');
        }
        $offers = $this->conn()->transactional(function (Connection $conn) use ($waitId, $slotId, $by) {
            $this->agenda->lockSlot($conn, (int) $slotId);
            $offers = $this->promoteIn($conn, (int) $slotId); // d'abord la file elle-même (offres échues, places libres)
            $w = $conn->fetchAssociative('SELECT status FROM benefit_waitlist WHERE id = ? FOR UPDATE', [$waitId]);
            if ($w !== false && $w['status'] === AboWaitlist::OFFERED) {
                return $offers; // la promotion vient de la lui offrir
            }
            if ($w === false || $w['status'] !== AboWaitlist::WAITING) {
                throw new AboException(404, 'WAITLIST_NOT_FOUND', 'Cette personne n\'attend plus une place sur ce créneau.', 'waitId');
            }
            if ($this->agenda->freePlaces($conn, (int) $slotId) < 1) {
                throw new AboException(409, 'SLOT_FULL', 'Aucune place libre sur ce créneau pour l\'instant.', 'slotId');
            }
            $start = new \DateTimeImmutable((string) $conn->fetchOne('SELECT starts_at FROM benefit_slot WHERE id = ?', [(int) $slotId]), new \DateTimeZone('UTC'));
            $this->offer($conn, $waitId, $start, $by);
            $offers[] = $waitId;

            return $offers;
        });
        $this->audit?->record('benefit_waitlist', (string) $waitId, 'offer', null, ['slotId' => (int) $slotId], ['abonnements']);
        $this->sendOffers($offers, $host);

        return $this->view($this->row($waitId), true);
    }

    // ---- promotion ------------------------------------------------------------------------------------------------------------------------

    /** Une place peut-être libérée (après la transaction qui l'a libérée) : proposée sous le verrou du créneau */
    #[AsEventListener]
    public function onSlotFreed(SlotFreedEvent $event): void
    {
        try {
            $this->promote($event->slotId);
        } catch (\Throwable $e) {
            // jamais bloquant pour le geste qui a libéré la place : le passage horaire rattrape
            $this->logger->error('[ABO-4] Promotion de la liste d\'attente en panne (' . $e::class . ') : le passage horaire la rattrape.', ['slot' => $event->slotId]);
        }
    }

    /** Propose les places libres d'un créneau, sous son verrou, puis envoie les courriels @return int offres faites */
    public function promote(int $slotId, string $host = ''): int
    {
        $conn = $this->conn();
        if ($conn->fetchOne("SELECT to_regclass('benefit_waitlist')") === null) {
            return 0;
        }
        $offers = $conn->transactional(function (Connection $conn) use ($slotId) {
            $this->agenda->lockSlot($conn, $slotId);

            return $this->promoteIn($conn, $slotId);
        });
        $this->sendOffers($offers, $host);

        return count($offers);
    }

    /** Le passage horaire : ferme les attentes sans objet, puis propose les places de chaque créneau qui a une file @return array{closed: int, offers: int} */
    public function sweep(): array
    {
        $conn = $this->conn();
        if ($conn->fetchOne("SELECT to_regclass('benefit_waitlist')") === null) {
            return ['closed' => 0, 'offers' => 0];
        }
        $closed = AboWaitlist::closeStale($conn);
        $offers = 0;
        foreach ($conn->fetchFirstColumn("SELECT DISTINCT slot_id FROM benefit_waitlist WHERE status IN ('waiting', 'offered') AND slot_id IS NOT NULL ORDER BY slot_id") as $slotId) {
            try {
                $offers += $this->promote((int) $slotId);
            } catch (AboException $e) {
                // créneau tenu par un autre geste (SLOT_BUSY) : au passage suivant
            }
        }

        return ['closed' => $closed, 'offers' => $offers];
    }

    /**
     * Sous le verrou du créneau : ferme les attentes sans objet, fait échoir les offres passées, puis offre chaque place libre à la première
     * personne en attente (pas à moins d'1 h du début). Rend les attentes offertes, à prévenir après la transaction.
     *
     * @return list<int>
     */
    private function promoteIn(Connection $conn, int $slotId): array
    {
        $now = new \DateTimeImmutable();
        AboWaitlist::closeStale($conn, null, $slotId);
        $conn->executeStatement("UPDATE benefit_waitlist SET status = 'expired', closed_at = ?, closed_reason = 'offer_expired' WHERE slot_id = ? AND status = 'offered' AND offer_expires_at <= ?",
            [AboWaitlist::utc($now), $slotId, AboWaitlist::utc($now)]);
        $slot = $conn->fetchAssociative('SELECT starts_at, status FROM benefit_slot WHERE id = ?', [$slotId]);
        if ($slot === false || $slot['status'] !== 'open') {
            return [];
        }
        $start = new \DateTimeImmutable((string) $slot['starts_at'], new \DateTimeZone('UTC'));
        if ($start <= $now->modify('+' . AboWaitlist::NO_AUTO_MINUTES . ' minutes')) {
            return []; // à moins d'1 h : plus de promotion automatique (l'équipe peut encore proposer)
        }
        $offered = [];
        for ($free = $this->agenda->freePlaces($conn, $slotId); $free > 0; --$free) {
            $next = $conn->fetchOne("SELECT id FROM benefit_waitlist WHERE slot_id = ? AND status = 'waiting' ORDER BY id LIMIT 1 FOR UPDATE", [$slotId]);
            if ($next === false) {
                break;
            }
            $this->offer($conn, (int) $next, $start, null);
            $offered[] = (int) $next;
        }

        return $offered;
    }

    private function offer(Connection $conn, int $waitId, \DateTimeImmutable $start, ?string $by): void
    {
        $now = new \DateTimeImmutable();
        $conn->executeStatement("UPDATE benefit_waitlist SET status = 'offered', offered_at = ?, offer_expires_at = ?, offered_by = ? WHERE id = ? AND status = 'waiting'",
            [AboWaitlist::utc($now), AboWaitlist::utc(AboWaitlist::offerDeadline($now, $start)), $by !== null ? mb_substr($by, 0, 180) : null, $waitId]);
    }

    /**
     * Le droit que porte une attente : un droit à planifier valable au début du créneau ; de préférence un que les autres attentes ouvertes
     * de l'abonné ne portent pas, et celui qui expire le DERNIER (une date posée directement prend celui qui expire le premier : l'attente
     * garde son droit).
     *
     * @throws AboException 409 BALANCE_EMPTY | SLOT_AFTER_VALIDITY
     */
    private function carriedRight(Connection $conn, int $subscriptionId, int $benefitId, string $startsAt): int
    {
        $now = AboWaitlist::utc(new \DateTimeImmutable());
        $id = $conn->fetchOne("SELECT o.id FROM benefit_occurrence o WHERE o.subscription_id = ? AND o.benefit_id = ? AND o.status = 'available' AND o.valid_until > ? AND o.valid_until >= ?
            ORDER BY (EXISTS (SELECT 1 FROM benefit_waitlist w WHERE w.occurrence_id = o.id AND w.status IN ('waiting', 'offered'))), o.valid_until DESC, o.id DESC LIMIT 1",
            [$subscriptionId, $benefitId, $now, $startsAt]);
        if ($id !== false) {
            return (int) $id;
        }
        if ($conn->fetchOne("SELECT 1 FROM benefit_occurrence WHERE subscription_id = ? AND benefit_id = ? AND status = 'available' AND valid_until > ? LIMIT 1", [$subscriptionId, $benefitId, $now]) !== false) {
            throw new AboException(409, 'SLOT_AFTER_VALIDITY', 'Ce créneau est après la fin de validité de vos séances.', 'slotId');
        }
        throw new AboException(409, 'BALANCE_EMPTY', 'Plus aucun droit à planifier pour cette prestation.', 'benefitId');
    }

    private function hasWaiting(Connection $conn, int $slotId): bool
    {
        return $conn->fetchOne("SELECT 1 FROM benefit_waitlist WHERE slot_id = ? AND status IN ('waiting', 'offered') LIMIT 1", [$slotId]) !== false;
    }

    /** Places libres hors transaction (lecture : sans verrou ni écriture) */
    private function freePlacesNow(int $slotId): int
    {
        return $this->agenda->freePlaces($this->conn(), $slotId);
    }

    // ---- vues et courriels ----------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $r = $this->conn()->fetchAssociative('SELECT * FROM benefit_waitlist WHERE id = ?', [$id]);

        return $r !== false ? $r : throw new AboException(404, 'WAITLIST_NOT_FOUND', 'Attente introuvable.', 'waitId');
    }

    /**
     * Une attente vue : une offre échue se montre `expired` sans rien écrire. Le rang compte les attentes ouvertes plus anciennes du créneau.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function view(array $r, bool $forTeam): array
    {
        $now = AboWaitlist::utc(new \DateTimeImmutable());
        $status = (string) $r['status'];
        if ($status === AboWaitlist::OFFERED && $r['offer_expires_at'] !== null && (string) $r['offer_expires_at'] <= $now) {
            $status = AboWaitlist::EXPIRED;
        }
        $position = in_array((string) $r['status'], AboWaitlist::OPEN, true) && $r['slot_id'] !== null
            ? 1 + (int) $this->conn()->fetchOne("SELECT COUNT(*) FROM benefit_waitlist WHERE slot_id = ? AND status IN ('waiting', 'offered') AND id < ?", [(int) $r['slot_id'], (int) $r['id']]) : null;
        $start = $r['slot_id'] !== null ? $this->conn()->fetchOne('SELECT starts_at FROM benefit_slot WHERE id = ?', [(int) $r['slot_id']]) : false;
        $view = ['waitId' => (int) $r['id'], 'slotId' => $r['slot_id'] !== null ? (int) $r['slot_id'] : null, 'benefitId' => (int) $r['benefit_id'], 'status' => $status, 'position' => $position,
            'startsAt' => is_string($start) ? $this->clock->atom($start) : null,
            'offerExpiresAt' => $r['offer_expires_at'] !== null ? $this->clock->atom((string) $r['offer_expires_at']) : null,
            'joinedAt' => $this->clock->atom((string) $r['created_at'])];
        if ($forTeam) {
            $view['subscriptionId'] = (int) $r['subscription_id'];
        }

        return $view;
    }

    /** @param list<int> $waitIds */
    private function sendOffers(array $waitIds, string $host): void
    {
        foreach (array_unique($waitIds) as $waitId) {
            try {
                $this->sendOffer($waitId, $host);
            } catch (\Throwable $e) {
                $this->logger->error('[ABO-4] Courriel d\'offre non envoyé (' . $e::class . ').', ['wait' => $waitId]);
            }
        }
    }

    /** Le courriel « une place s'est libérée » (INDISPENSABLE) : dans la langue de l'abonné, échéance à l'heure du magasin */
    private function sendOffer(int $waitId, string $host): void
    {
        if ($this->sender === null || $this->translator === null || $this->locale === null) {
            return;
        }
        $r = $this->conn()->fetchAssociative('SELECT w.subscription_id, w.benefit_id, w.offer_expires_at, sl.starts_at FROM benefit_waitlist w JOIN benefit_slot sl ON sl.id = w.slot_id WHERE w.id = ? AND w.status = ?',
            [$waitId, AboWaitlist::OFFERED]);
        if ($r === false) {
            return;
        }
        $subscription = $this->emProvider->getEntityManager()->getRepository(Subscription::class)->find((int) $r['subscription_id']);
        $email = $subscription?->getUser()?->getEmail();
        if ($subscription === null || !$email) {
            return;
        }
        $language = $this->locale->forUser($subscription->getUser());
        $benefit = $this->emProvider->getEntityManager()->getRepository(\App\Abo\Entity\Benefit::class)->find((int) $r['benefit_id']);
        $tz = $this->clock->timezone();
        $params = ['%benefit%' => (string) $benefit?->getName($language),
            '%date%' => (new \DateTimeImmutable((string) $r['starts_at'], new \DateTimeZone('UTC')))->setTimezone($tz)->format('d/m/Y H:i'),
            '%deadline%' => (new \DateTimeImmutable((string) $r['offer_expires_at'], new \DateTimeZone('UTC')))->setTimezone($tz)->format('d/m/Y H:i')];
        $t = fn (string $key) => $this->translator->trans('waitlist_offer.' . $key, $params + ['%name%' => (string) $subscription->getUser()?->getFirstname()], 'emails', $language);
        $subject = $t('subject');
        $send = fn (): bool => $this->sender->sendTemplatedEmail($email, $subject, 'emails/subscription_waitlist_offer.html.twig', [
            'title' => $t('title'), 'greeting' => $t('greeting'), 'message' => $t('message'), 'deadline' => $t('deadline'), 'action' => $t('action'),
        ], $language, ($this->urls?->getEmailLogosBaseUrl($host !== '' ? $host : null) ?? '') . '/');
        $send(); // INDISPENSABLE (catalogue : `NotificationCatalog::SUBSCRIPTION_WAITLIST_OFFER`) : jamais retenu ni écarté par les niveaux du commerçant
    }

    private function conn(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
