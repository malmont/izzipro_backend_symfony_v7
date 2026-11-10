<?php

namespace App\Repository;

use Doctrine\ORM\EntityRepository;

/** Appareils inscrits aux notifications (lot N1, `docs/notifications-gestion.md`) : inscription idempotente par jeton, appareils actifs. */
class PushDeviceRepository extends EntityRepository
{
    /** @return array{created: bool, device: array<string, mixed>} */
    public function register(int $userId, string $platform, string $token, ?string $deviceName, ?string $locale, ?string $appVersion): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $existed = $conn->fetchOne('SELECT 1 FROM push_device WHERE token = ?', [$token]) !== false;
        $conn->executeStatement(
            'INSERT INTO push_device (user_id, platform, token, device_name, locale, app_version, created_at, last_seen_at) VALUES (:u, :p, :t, :n, :l, :v, NOW(), NOW())'
            . ' ON CONFLICT (token) DO UPDATE SET user_id = EXCLUDED.user_id, platform = EXCLUDED.platform, device_name = COALESCE(EXCLUDED.device_name, push_device.device_name),'
            . ' locale = COALESCE(EXCLUDED.locale, push_device.locale), app_version = COALESCE(EXCLUDED.app_version, push_device.app_version),'
            . ' last_seen_at = NOW(), disabled_at = NULL, disabled_reason = NULL',
            ['u' => $userId, 'p' => $platform, 't' => $token, 'n' => $deviceName, 'l' => $locale, 'v' => $appVersion]
        );

        return ['created' => !$existed, 'device' => $this->one($token)];
    }

    /** Désactive l'appareil (déconnexion : seulement celui de cet utilisateur ; jeton refusé : `$userId` null) ; idempotent */
    public function disable(string $token, string $reason, ?int $userId = null): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE push_device SET disabled_at = NOW(), disabled_reason = ? WHERE token = ? AND disabled_at IS NULL' . ($userId !== null ? ' AND user_id = ' . (int) $userId : ''), [$reason, $token]
        );
    }

    /** @return list<array{id: int, token: string, locale: ?string}> appareils actifs d'un utilisateur pour une application */
    public function activeFor(int $userId, string $platform): array
    {
        return array_map(fn (array $r) => ['id' => (int) $r['id'], 'token' => (string) $r['token'], 'locale' => $r['locale']], $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, token, locale FROM push_device WHERE user_id = ? AND platform = ? AND disabled_at IS NULL ORDER BY last_seen_at DESC, id', [$userId, $platform]
        ));
    }

    /** @return list<int> utilisateurs qui ont au moins un appareil actif pour cette application */
    public function usersWithActiveDevice(string $platform): array
    {
        return array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn('SELECT DISTINCT user_id FROM push_device WHERE platform = ? AND disabled_at IS NULL ORDER BY user_id', [$platform]));
    }

    /** @return array<string, mixed> */
    public function one(string $token): array
    {
        $r = $this->getEntityManager()->getConnection()->fetchAssociative('SELECT id, platform, device_name, locale, app_version, created_at, last_seen_at, disabled_at FROM push_device WHERE token = ?', [$token]) ?: [];

        return $r === [] ? [] : [
            'id' => (int) $r['id'], 'platform' => $r['platform'], 'deviceName' => $r['device_name'], 'locale' => $r['locale'], 'appVersion' => $r['app_version'],
            'createdAt' => self::iso($r['created_at']), 'lastSeenAt' => self::iso($r['last_seen_at']), 'active' => $r['disabled_at'] === null,
        ];
    }

    private static function iso(?string $utc): ?string
    {
        return $utc === null ? null : (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->format(\DATE_ATOM);
    }
}
