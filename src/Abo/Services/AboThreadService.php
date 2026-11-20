<?php

namespace App\Abo\Services;

use App\Abo\Entity\SubscriptionMessage;
use App\Entity\Entreprise;
use App\Entity\Subscription;
use App\Entity\User;
use App\Services\CaisseService\StoreClock;
use App\Services\Notification\NotificationCatalog;
use App\Services\Notification\NotificationGate;
use App\Services\SubscriptionService\SubscriptionMailer;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;

/**
 * Le fil de l'abonné (lot ABO-3b, 20/11/2026, `docs/boutique.md` § « Intervenant et fil de l'abonné ») : messages entre l'abonné et l'équipe,
 * rattachés à l'abonnement, sans dépendre du module Assistance.
 * - Texte brut (balises retirées), 1 à 2000 caractères ; l'abonné écrit 20 messages par jour et par abonnement au plus (429).
 * - Lire le fil marque lus les messages de l'autre partie.
 * - À l'abonné : courriel « Vous avez un message », sans le texte (le lien ouvre son compte), au plus un par heure et par abonnement ;
 *   indispensable, comme tout courriel à un client.
 * - À l'équipe : courriel de suivi `subscription_message` (sujet « assistance »), selon les niveaux du site (NOTIF).
 * - Une contestation (ABO-3a) y écrit son commentaire.
 * - Au modèle : textes masqués (courriels, téléphones), sans nom, auteur « abonné » ou « équipe ».
 * - Effacé 24 mois après la fin de l'abonnement (`app:abo:periodes`).
 * L'intervenant n'y a jamais accès ; le directeur d'un groupe entré non plus (`EntryModeGuardSubscriber`).
 */
class AboThreadService
{
    public const PAGE = 50;
    public const MODEL_MAX = 30;
    public const CUSTOMER_DAILY_MAX = 20;
    public const EMAIL_EVERY_MINUTES = 60;
    public const KEEP_MONTHS = 24;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly StoreClock $clock,
        private readonly ?SubscriptionMailer $mailer = null,
        private readonly ?NotificationGate $gate = null,
        private readonly ?\App\Services\EmailConfigurationService\EmailSenderService $sender = null,
    ) {
    }

    /**
     * Le fil vu par l'abonné : messages du plus ancien au plus récent (les 50 derniers, ou ceux d'avant `before`) ; les messages de l'équipe
     * sont marqués lus.
     *
     * @return array{messages: list<array<string, mixed>>, unread: int}
     */
    public function forCustomer(Subscription $subscription, mixed $before = null): array
    {
        $messages = $this->page($subscription, $before, false);
        $this->markRead($subscription, SubscriptionMessage::AUTHOR_TEAM);

        return ['messages' => $messages, 'unread' => 0];
    }

    /**
     * L'abonné écrit.
     *
     * @return array{message: array<string, mixed>}
     * @throws AboException 422 BODY_REQUIRED | BODY_TOO_LONG, 429 THREAD_RATE_LIMITED
     */
    public function postByCustomer(Subscription $subscription, User $customer, mixed $body, string $host, string $locale): array
    {
        $text = self::text($body);
        // relecture neuve : compté puis écrit sous le verrou de la ligne de l'abonnement (deux envois simultanés ne dépassent jamais 20) ;
        // une contestation (ABO-3a) n'entre pas dans le compte
        $message = $this->conn()->transactional(function (Connection $conn) use ($subscription, $customer, $text) {
            $conn->fetchOne('SELECT id FROM subscription WHERE id = ? FOR UPDATE', [$subscription->getId()]);
            $since = self::utc((new \DateTimeImmutable())->modify('-1 day'));
            $sent = (int) $conn->fetchOne('SELECT COUNT(*) FROM subscription_message WHERE subscription_id = ? AND author = ? AND occurrence_id IS NULL AND created_at > ?', [$subscription->getId(), SubscriptionMessage::AUTHOR_CUSTOMER, $since]);
            if ($sent >= self::CUSTOMER_DAILY_MAX) {
                throw new AboException(429, 'THREAD_RATE_LIMITED', sprintf('%d messages par jour au plus : la boutique vous répond dès que possible.', self::CUSTOMER_DAILY_MAX));
            }

            return $this->add($subscription, SubscriptionMessage::AUTHOR_CUSTOMER, $text, (int) $customer->getId(), self::firstname($customer));
        });
        $this->notifyTeam($subscription, $host);

        return ['message' => $this->view($message, false)];
    }

    /**
     * Le fil vu par l'équipe ; les messages de l'abonné sont marqués lus.
     *
     * @return array{messages: list<array<string, mixed>>}
     */
    public function forTeam(Subscription $subscription, mixed $before = null): array
    {
        $messages = $this->page($subscription, $before, true);
        $this->markRead($subscription, SubscriptionMessage::AUTHOR_CUSTOMER);

        return ['messages' => $messages];
    }

    /**
     * L'équipe répond ; un courriel à l'abonné au plus par heure et par abonnement.
     *
     * @return array{message: array<string, mixed>, emailed: bool}
     * @throws AboException 422 BODY_REQUIRED | BODY_TOO_LONG
     */
    public function postByTeam(Subscription $subscription, mixed $body, ?User $by, string $host, string $locale): array
    {
        $text = self::text($body);
        $message = $this->add($subscription, SubscriptionMessage::AUTHOR_TEAM, $text, $by?->getId(), $by !== null ? self::firstname($by) : null);
        $now = new \DateTimeImmutable();
        $emailed = $this->mailer !== null && $this->conn()->executeStatement('UPDATE subscription SET thread_emailed_at = ? WHERE id = ? AND (thread_emailed_at IS NULL OR thread_emailed_at < ?)',
            [self::utc($now), $subscription->getId(), self::utc($now->modify('-' . self::EMAIL_EVERY_MINUTES . ' minutes'))]) === 1;
        if ($emailed) {
            $this->mailer->notify($subscription, 'thread_message', $locale, $host);
        }

        return ['message' => $this->view($message, true), 'emailed' => $emailed];
    }

    /** La contestation d'un constat (ABO-3a), écrite dans le fil par l'abonné */
    public function recordContestation(Subscription $subscription, User $customer, int $occurrenceId, string $comment): void
    {
        $this->add($subscription, SubscriptionMessage::AUTHOR_CUSTOMER, $comment, (int) $customer->getId(), self::firstname($customer), $occurrenceId);
    }

    /**
     * Les abonnements dont l'abonné a écrit sans être lu, du plus récent au plus ancien (l'identité pour l'équipe seulement).
     *
     * @return list<array<string, mixed>>
     */
    public function unreadThreads(bool $withIdentity, string $locale = 'fr'): array
    {
        // relecture neuve : `names` est du JSON, que PostgreSQL ne sait pas grouper ; le compte d'abord, les noms ensuite
        $rows = $this->conn()->fetchAllAssociative('SELECT t.subscription_id, t.unread, t.last_at, u.firstname, u.lastname, p.names
            FROM (SELECT subscription_id, COUNT(*) AS unread, MAX(created_at) AS last_at FROM subscription_message WHERE author = ? AND read_at IS NULL GROUP BY subscription_id) t
            JOIN subscription s ON s.id = t.subscription_id LEFT JOIN "user" u ON u.id = s.user_id LEFT JOIN subscription_plan p ON p.id = s.plan_id
            ORDER BY t.last_at DESC LIMIT 100', [SubscriptionMessage::AUTHOR_CUSTOMER]);

        return array_map(function (array $r) use ($withIdentity, $locale) {
            $names = is_string($r['names']) ? (json_decode($r['names'], true) ?: []) : [];

            return ['subscriptionId' => (int) $r['subscription_id'], 'plan' => $names[$locale] ?? $names['fr'] ?? (reset($names) ?: null), 'unread' => (int) $r['unread'], 'lastAt' => $this->at($r['last_at'])]
                + ($withIdentity ? ['customer' => ['firstname' => $r['firstname'], 'lastname' => $r['lastname']]] : []);
        }, $rows);
    }

    /** Messages de l'équipe pas encore lus par l'abonné (sa fiche) */
    public function unreadForCustomer(Subscription $subscription): int
    {
        return (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM subscription_message WHERE subscription_id = ? AND author = ? AND read_at IS NULL', [$subscription->getId(), SubscriptionMessage::AUTHOR_TEAM]);
    }

    /** Messages de l'abonné pas encore lus par l'équipe (fiche de l'équipe) */
    public function unreadForTeam(Subscription $subscription): int
    {
        return (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM subscription_message WHERE subscription_id = ? AND author = ? AND read_at IS NULL', [$subscription->getId(), SubscriptionMessage::AUTHOR_CUSTOMER]);
    }

    /**
     * Le fil pour le modèle (outil `get_subscription_thread`) : les 30 derniers messages, textes masqués, sans nom ; rien n'est marqué lu.
     *
     * @return list<array{author: string, at: ?string, text: string}>
     */
    public function forModel(Subscription $subscription): array
    {
        $rows = $this->conn()->fetchAllAssociative('SELECT author, body, created_at FROM subscription_message WHERE subscription_id = ? ORDER BY id DESC LIMIT ' . self::MODEL_MAX, [$subscription->getId()]);

        return array_reverse(array_map(fn (array $r) => ['author' => $r['author'] === SubscriptionMessage::AUTHOR_CUSTOMER ? 'abonné' : 'équipe', 'at' => $this->at($r['created_at']),
            'text' => AboDeliveryService::maskContacts((string) $r['body'])], $rows));
    }

    /** Efface les fils des abonnements terminés depuis plus de 24 mois ; rend le nombre de messages effacés */
    public function purge(?\DateTimeImmutable $now = null): int
    {
        $limit = self::utc(($now ?? new \DateTimeImmutable())->modify('-' . self::KEEP_MONTHS . ' months'));

        // relecture neuve : un abonnement résilié, ou jamais payé (`incomplete`), daté par la résiliation ou, à défaut, par la dernière modification
        return (int) $this->conn()->executeStatement("DELETE FROM subscription_message m USING subscription s WHERE s.id = m.subscription_id AND s.status IN ('canceled', 'incomplete')
            AND COALESCE(s.canceled_at, s.updated_at) < ?", [$limit]);
    }

    // ---- interne ----------------------------------------------------------------------------------------------------------------------------

    private function add(Subscription $subscription, string $author, string $text, ?int $userId, ?string $name, ?int $occurrenceId = null): SubscriptionMessage
    {
        $em = $this->emProvider->getEntityManager();
        $message = new SubscriptionMessage($subscription, $author, $text, $userId, $name, $occurrenceId);
        $em->persist($message);
        $em->flush();

        return $message;
    }

    /** @return list<array<string, mixed>> du plus ancien au plus récent */
    private function page(Subscription $subscription, mixed $before, bool $forTeam): array
    {
        $params = [$subscription->getId()];
        $where = 'subscription_id = ?';
        if ($before !== null && $before !== '') {
            if (!is_int($before) && !(is_string($before) && ctype_digit($before))) {
                throw new AboException(422, 'BEFORE_INVALID', 'Numéro de message attendu.', 'before');
            }
            $where .= ' AND id < ?';
            $params[] = (int) $before;
        }
        $rows = $this->conn()->fetchAllAssociative('SELECT id, author, author_name, body, occurrence_id, created_at, read_at FROM subscription_message WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . self::PAGE, $params);

        return array_reverse(array_map(fn (array $r) => $this->row($r, $forTeam), $rows));
    }

    /** @return array<string, mixed> */
    private function view(SubscriptionMessage $m, bool $forTeam): array
    {
        return $this->row(['id' => $m->getId(), 'author' => $m->getAuthor(), 'author_name' => $m->getAuthorName(), 'body' => $m->getBody(), 'occurrence_id' => $m->getOccurrenceId(),
            'created_at' => $m->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'read_at' => null], $forTeam);
    }

    /**
     * Un message : l'abonné voit le prénom de la personne de l'équipe ; l'équipe voit le prénom de l'abonné.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function row(array $r, bool $forTeam): array
    {
        return ['id' => (int) $r['id'], 'author' => (string) $r['author'], 'authorName' => $r['author_name'], 'body' => (string) $r['body'],
            'occurrenceId' => $r['occurrence_id'] !== null ? (int) $r['occurrence_id'] : null, 'createdAt' => $this->at($r['created_at']), 'read' => $r['read_at'] !== null];
    }

    private function markRead(Subscription $subscription, string $author): void
    {
        $this->conn()->executeStatement('UPDATE subscription_message SET read_at = ? WHERE subscription_id = ? AND author = ? AND read_at IS NULL', [self::utc(new \DateTimeImmutable()), $subscription->getId(), $author]);
    }

    /** L'équipe : courriel de suivi à l'adresse du site, selon ses niveaux (NOTIF) ; jamais le texte du message */
    private function notifyTeam(Subscription $subscription, string $host): void
    {
        $to = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([])?->getEmail();
        if ($this->sender === null || !$to) {
            return;
        }
        $subject = sprintf('Un abonné vous a écrit (abonnement n° %d)', (int) $subscription->getId());
        $send = fn (): bool => $this->sender->sendTemplatedEmail($to, $subject, 'emails/subscription_team_message.html.twig', [
            'title' => $subject, 'subscriptionId' => (int) $subscription->getId(), 'plan' => $subscription->getPlan()?->getName('fr'),
        ], 'fr', $host);
        try {
            $this->gate !== null ? $this->gate->deliver(NotificationCatalog::SUBSCRIPTION_MESSAGE, $to, $subject, ['screen' => 'abonnements', 'params' => ['id' => (int) $subscription->getId()]], $send) : $send();
        } catch (\Throwable) {
            // jamais bloquant : le message est enregistré, l'équipe le voit dans « fils »
        }
    }

    /** @throws AboException 422 BODY_REQUIRED | BODY_TOO_LONG */
    private static function text(mixed $body): string
    {
        $text = is_string($body) ? trim(strip_tags($body)) : '';
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        if ($text === '') {
            throw new AboException(422, 'BODY_REQUIRED', 'Écrivez votre message.', 'body');
        }
        if (mb_strlen($text) > SubscriptionMessage::BODY_MAX) {
            throw new AboException(422, 'BODY_TOO_LONG', sprintf('%d caractères au plus.', SubscriptionMessage::BODY_MAX), 'body');
        }

        return $text;
    }

    private static function firstname(User $u): ?string
    {
        $name = trim((string) $u->getFirstname());

        return $name !== '' ? mb_substr($name, 0, 100) : null;
    }

    private function at(mixed $utc): ?string
    {
        return $utc === null ? null : $this->clock->atom(new \DateTimeImmutable((string) $utc, new \DateTimeZone('UTC')));
    }

    private function conn(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
