<?php

namespace App\Repository;

use Doctrine\ORM\EntityRepository;

/**
 * Journal et préférences des notifications (lot N1, `docs/notifications-gestion.md`) : limite d'une par jour du magasin, sujets déjà
 * notifiés, journal d'un utilisateur, types coupés.
 */
class NotificationLogRepository extends EntityRepository
{
    public const COUNTED = ['sent', 'not_configured', 'failed']; // tentatives qui comptent dans la limite (pas no_device)

    public function add(int $userId, string $kind, string $title, string $body, array $action, array $subject, string $status, int $devices, ?string $error, string $storeDay): int
    {
        $conn = $this->getEntityManager()->getConnection();

        return (int) $conn->fetchOne(
            'INSERT INTO notification_log (user_id, kind, title, body, action, subject, status, devices, error, store_day, sent_at) VALUES (:u, :k, :t, :b, :a, :s, :st, :d, :e, :day, NOW()) RETURNING id',
            ['u' => $userId, 'k' => $kind, 't' => mb_substr($title, 0, 255), 'b' => $body, 'a' => json_encode($action, \JSON_UNESCAPED_UNICODE), 's' => json_encode($subject), 'st' => $status, 'd' => $devices, 'e' => $error !== null ? mb_substr($error, 0, 255) : null, 'day' => $storeDay]
        );
    }

    public function usedToday(int $userId, string $storeDay): bool
    {
        return $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM notification_log WHERE user_id = ? AND store_day = ? AND status IN (?, ?, ?) LIMIT 1', [$userId, $storeDay, ...self::COUNTED]
        ) !== false;
    }

    /** @return list<array<string, mixed>> sujets des notifications de ce type depuis cette date (UTC) */
    public function subjectsSince(int $userId, string $kind, \DateTimeImmutable $sinceUtc): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT subject FROM notification_log WHERE user_id = ? AND kind = ? AND sent_at >= ? AND status IN (?, ?, ?)', [$userId, $kind, $sinceUtc->format('Y-m-d H:i:s'), ...self::COUNTED]
        );

        return array_map(fn ($s) => json_decode((string) $s, true) ?: [], $rows);
    }

    /** @return list<array<string, mixed>> */
    public function journal(int $userId, int $limit): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, kind, title, body, action, status, sent_at FROM notification_log WHERE user_id = ? ORDER BY sent_at DESC, id DESC LIMIT ' . max(1, min(100, $limit)), [$userId]
        );

        return array_map(fn (array $r) => [
            'id' => (int) $r['id'], 'kind' => $r['kind'], 'title' => $r['title'], 'body' => $r['body'], 'action' => json_decode((string) $r['action'], true) ?: new \stdClass(),
            'status' => $r['status'], 'sentAt' => (new \DateTimeImmutable($r['sent_at'], new \DateTimeZone('UTC')))->format(\DATE_ATOM),
        ], $rows);
    }

    /** @return array<string, bool> type → actif, pour les types réglés par cet utilisateur */
    public function preferences(int $userId): array
    {
        $out = [];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative('SELECT kind, enabled FROM notification_preference WHERE user_id = ?', [$userId]) as $r) {
            $out[(string) $r['kind']] = (bool) $r['enabled'];
        }

        return $out;
    }

    public function setPreference(int $userId, string $kind, bool $enabled): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO notification_preference (user_id, kind, enabled, updated_at) VALUES (:u, :k, :e, NOW()) ON CONFLICT (user_id, kind) DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = NOW()',
            ['u' => $userId, 'k' => $kind, 'e' => $enabled], ['e' => \Doctrine\DBAL\ParameterType::BOOLEAN]
        );
    }
}
