<?php

namespace App\Services\AppMembershipService;

use App\Entity\User;
use App\Services\TenantConnectionProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Jetons de passage de l'étape « Rejoindre » (lot APPS-MEMBRES M3, `docs/apps-membres.md` § 6). Rendu avec le refus
 * `APP_MEMBERSHIP_REQUIRED`, un jeton prouve que le mot de passe (et le code, s'il y en a un) vient d'être vérifié : il ne sert
 * qu'à `POST /api/apps/join` et ne donne aucun accès à l'API. Valeur aléatoire, 10 minutes, à usage UNIQUE, liée au site, au
 * compte, à la famille et à la plateforme de la connexion. Gardé côté serveur, dans le cache sans éviction du limiteur de débit
 * (jamais en base), sous l'empreinte du jeton : le cache ne contient pas la valeur que le client présente.
 */
final class AppJoinTokens
{
    public const TTL_SECONDS = 600;

    public function __construct(
        #[Autowire(service: 'cache.rate_limiter')] private readonly CacheItemPoolInterface $cache,
        private readonly TenantConnectionProvider $tenant,
    ) {
    }

    /** @return array{token: string, expiresAt: string} */
    public function issue(User $user, string $family, string $platform, string $reason): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('+' . self::TTL_SECONDS . ' seconds');
        $item = $this->cache->getItem(self::key($token));
        $item->set(['site' => (string) $this->tenant->getTenantCode(), 'userId' => (int) $user->getId(), 'app' => $family, 'platform' => $platform, 'reason' => $reason, 'expiresAt' => $expiresAt->getTimestamp()]);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);

        return ['token' => $token, 'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM)];
    }

    /**
     * Ce que le jeton prouve, s'il est encore valable pour CE site ; null : inconnu, déjà utilisé, expiré, ou d'un autre site.
     *
     * @return array{userId: int, app: string, platform: string, reason: string}|null
     */
    public function read(mixed $token): ?array
    {
        if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }
        $data = $this->cache->getItem(self::key($token))->get();
        if (!is_array($data) || ($data['site'] ?? null) !== (string) $this->tenant->getTenantCode() || (int) ($data['expiresAt'] ?? 0) < time()) {
            return null;
        }

        return ['userId' => (int) $data['userId'], 'app' => (string) $data['app'], 'platform' => (string) $data['platform'], 'reason' => (string) $data['reason']];
    }

    /** Le jeton a servi : il ne servira plus */
    public function consume(string $token): void
    {
        $this->cache->deleteItem(self::key($token));
    }

    private static function key(string $token): string
    {
        return 'app_join.' . hash('sha256', $token);
    }
}
