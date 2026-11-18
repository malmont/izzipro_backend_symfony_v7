<?php

namespace App\Incident\Services;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Limites des rapports d'incident (lot INC-1a, 18/11/2026) : une application en boucle d'erreurs ne remplit pas la base maître.
 * - `anonymous` : rapports sans jeton, par site et par adresse IP ;
 * - `user` : rapports d'un compte connecté, par site et par compte.
 * Le plafond des incidents NOUVEAUX sans jeton par site et par jour (`DAILY_NEW_ANONYMOUS`) se lit dans la base (`PlatformIncidentService`).
 */
class IncidentLimits
{
    public const LIMITS = ['anonymous' => 30, 'user' => 120];
    private const INTERVALS = ['anonymous' => '5 minutes', 'user' => '5 minutes'];

    /** @var array<string, RateLimiterFactory> */
    private array $factories = [];

    /** @param array<string, int> $limits plafonds à la place de ceux de `LIMITS` (essais) */
    public function __construct(#[Autowire(service: 'cache.rate_limiter')] CacheItemPoolInterface $pool, array $limits = [])
    {
        $storage = new CacheStorage($pool);
        foreach (self::LIMITS as $name => $limit) {
            $this->factories[$name] = new RateLimiterFactory(['id' => 'platform_incident_' . $name, 'policy' => 'sliding_window', 'limit' => $limits[$name] ?? $limit, 'interval' => self::INTERVALS[$name]], $storage);
        }
    }

    /** Un rapport sans jeton depuis cette adresse, pour ce site ; rend l'attente en secondes, ou null */
    public function anonymous(?string $site, string $ip): ?int
    {
        return $this->consume('anonymous', ($site ?? '-') . '|' . $ip);
    }

    /** Un rapport de ce compte, pour ce site ; rend l'attente en secondes, ou null */
    public function user(?string $site, int $userId): ?int
    {
        return $this->consume('user', ($site ?? '-') . '|' . $userId);
    }

    public function reset(string $name, string $key): void
    {
        $this->factories[$name]->create($key)->reset();
    }

    private function consume(string $name, string $key): ?int
    {
        $limit = $this->factories[$name]->create($key)->consume(1);

        return $limit->isAccepted() ? null : max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }
}
