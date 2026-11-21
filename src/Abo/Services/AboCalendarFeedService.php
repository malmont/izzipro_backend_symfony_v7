<?php

namespace App\Abo\Services;

use App\Entity\Subscription;
use App\Entity\User;
use App\Security\TeamRoles;
use App\Services\Calendar\IcsBuilder;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;

/**
 * Liens d'agenda `.ics` (lot ABO-4, 21/11/2026, contrat validé par le chef, `docs/boutique.md` § « Liste d'attente, rappels et calendrier ») :
 * un par abonné (ses dates, la prestation, le lieu) et un par intervenant (comme son écran : prénom et adresse de la visite, jamais le nom
 * ni le téléphone). Lu par les agendas (Google, Apple…) sans JWT : le jeton (32 octets au hasard) est dans l'adresse et gardé HACHÉ ;
 * « Changer le lien » rend l'ancien inutilisable. Le même 404 pour un jeton inconnu, révoqué, un abonnement terminé ou un intervenant qui
 * a perdu son rôle ou dont le compte est supprimé. La lecture n'écrit rien. Le jeton n'apparaît jamais dans nos traces
 * (`SensitivePathProcessor`, `PersonalDataMask::calendarPaths`).
 */
class AboCalendarFeedService
{
    public const OWNER_SUBSCRIPTION = 'subscription';
    public const OWNER_STAFF = 'staff';
    public const PATH = '/api/calendrier/';
    /** L'abonné : ses dates de 30 jours en arrière à 8 semaines devant (l'horizon des créneaux) */
    private const SUBSCRIBER_BACK_DAYS = 30;
    private const SUBSCRIBER_AHEAD_DAYS = 56;
    private const LIVE_STATUSES = [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING, Subscription::STATUS_PAST_DUE, Subscription::STATUS_PAUSED];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly AboStaffWorkService $staffWork,
        private readonly AboReminderService $reminders,
        private readonly EmailLocale $locale,
    ) {
    }

    /**
     * Un lien neuf (le précédent ne vaut plus) : l'adresse est rendue une fois, à son propriétaire ; seul son condensé est gardé.
     *
     * @param self::OWNER_* $ownerType
     * @return array{url: string, createdAt: string}
     */
    public function rotate(string $ownerType, int $ownerId, string $host): array
    {
        $token = bin2hex(random_bytes(32));
        $now = gmdate('Y-m-d H:i:s');
        $this->conn()->transactional(function (Connection $conn) use ($ownerType, $ownerId, $token, $now) {
            $conn->executeStatement('DELETE FROM calendar_feed WHERE owner_type = ? AND owner_id = ?', [$ownerType, $ownerId]);
            $conn->executeStatement('INSERT INTO calendar_feed (owner_type, owner_id, token_hash, created_at) VALUES (?, ?, ?, ?)', [$ownerType, $ownerId, self::hash($token), $now]);
        });

        return ['url' => 'https://' . $host . self::PATH . $token . '.ics', 'createdAt' => (string) date(\DATE_ATOM, (int) strtotime($now . ' UTC'))];
    }

    /** Le lien existe-t-il (sans le rendre : il n'est montré qu'à sa création) @param self::OWNER_* $ownerType */
    public function exists(string $ownerType, int $ownerId): bool
    {
        return $this->conn()->fetchOne('SELECT 1 FROM calendar_feed WHERE owner_type = ? AND owner_id = ?', [$ownerType, $ownerId]) !== false;
    }

    /**
     * Le contenu d'un lien, ou null (le même 404 pour toute raison) : jeton inconnu ou révoqué, abonnement terminé, intervenant sans rôle.
     */
    public function feed(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = $this->conn()->fetchAssociative('SELECT owner_type, owner_id FROM calendar_feed WHERE token_hash = ?', [self::hash($token)]);
        if ($row === false) {
            return null;
        }

        return match ((string) $row['owner_type']) {
            self::OWNER_SUBSCRIPTION => $this->subscriberFeed((int) $row['owner_id']),
            self::OWNER_STAFF => $this->staffFeed((int) $row['owner_id']),
            default => null,
        };
    }

    private function subscriberFeed(int $subscriptionId): ?string
    {
        $subscription = $this->emProvider->getEntityManager()->getRepository(Subscription::class)->find($subscriptionId);
        if ($subscription === null || !in_array($subscription->getStatus(), self::LIVE_STATUSES, true) || $subscription->getUser()?->getDeletedAt() !== null) {
            return null;
        }
        $now = new \DateTimeImmutable();
        $ids = array_map('intval', $this->conn()->fetchFirstColumn("SELECT id FROM benefit_occurrence WHERE subscription_id = ? AND status IN ('scheduled', 'done', 'missed') AND scheduled_at >= ? AND scheduled_at < ? ORDER BY scheduled_at, id LIMIT 300",
            [$subscriptionId, self::utc($now->modify('-' . self::SUBSCRIBER_BACK_DAYS . ' days')), self::utc($now->modify('+' . self::SUBSCRIBER_AHEAD_DAYS . ' days'))]));
        $language = $this->locale->forUser($subscription->getUser());

        return $this->reminders->ics($ids, $language) ?? IcsBuilder::calendar([], (string) ($subscription->getPlan()?->getName($language) ?? ''));
    }

    private function staffFeed(int $userId): ?string
    {
        $user = $this->emProvider->getEntityManager()->getRepository(User::class)->find($userId);
        if ($user === null || $user->getDeletedAt() !== null || !in_array(TeamRoles::STAFF_PROVIDER, $user->getRoles(), true)) {
            return null;
        }
        $language = $this->locale->forUser($user);
        $events = [];
        foreach ($this->staffWork->feedRows($userId, $language) as $r) {
            if ($r['startsAt'] === null) {
                continue;
            }
            $address = $r['address'] !== null ? implode(', ', array_filter([$r['address']['line1'], $r['address']['line2'], trim($r['address']['postalCode'] . ' ' . $r['address']['city']), $r['address']['province'], $r['address']['country']],
                fn ($v) => $v !== null && trim((string) $v) !== '')) : null;
            $start = new \DateTimeImmutable((string) $r['startsAt']);
            $events[] = ['uid' => $this->reminders->uid((int) $r['occurrenceId'], $start), 'start' => $start, 'end' => $r['endsAt'] !== null ? new \DateTimeImmutable((string) $r['endsAt']) : $start->modify('+1 hour'),
                // le prénom seul, comme l'écran : jamais le nom de famille ni le téléphone
                'summary' => trim($r['name'] . (($r['customer']['firstname'] ?? null) ? ' · ' . $r['customer']['firstname'] : '')), 'location' => $address ?? $r['location']];
        }

        return IcsBuilder::calendar($events, $language === 'en' ? 'My appointments' : 'Mes interventions');
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function conn(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
