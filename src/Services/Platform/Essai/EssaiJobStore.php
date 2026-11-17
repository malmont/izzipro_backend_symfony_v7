<?php

namespace App\Services\Platform\Essai;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * L'état du dernier remplissage d'un site d'essai demandé par le bouton du web (lot ESSAI-DONNÉES) : `queued`, `running`, `done`,
 * `failed`, avec qui l'a demandé, les heures et le compte rendu. Gardé 7 jours dans le cache partagé (Redis), lu par l'API, écrit par
 * le worker : aucune table. Une seule exécution à la fois par site.
 */
class EssaiJobStore
{
    public const TTL = 604800;
    /** Une exécution en attente ou en cours depuis plus longtemps est tenue pour perdue (worker arrêté) : on peut relancer */
    public const STALE_AFTER = 3600;

    public function __construct(#[Autowire(service: 'cache.app')] private readonly CacheInterface $cache)
    {
    }

    /** @return array<string, mixed>|null */
    public function get(string $tenantCode): ?array
    {
        $state = $this->cache->get(self::key($tenantCode), static fn (ItemInterface $item) => null);

        return is_array($state) ? $state : null;
    }

    /** @param array<string, mixed> $state */
    public function put(string $tenantCode, array $state): void
    {
        $this->cache->delete(self::key($tenantCode));
        $this->cache->get(self::key($tenantCode), static function (ItemInterface $item) use ($state): array {
            $item->expiresAfter(self::TTL);

            return $state;
        });
    }

    /** Une exécution est-elle en attente ou en cours (et pas perdue) ? */
    public function busy(string $tenantCode): bool
    {
        $state = $this->get($tenantCode);
        if ($state === null || !in_array($state['state'] ?? null, ['queued', 'running'], true)) {
            return false;
        }

        return (time() - (int) ($state['since'] ?? 0)) < self::STALE_AFTER;
    }

    private static function key(string $tenantCode): string
    {
        return 'essai_job_' . preg_replace('/[^a-z0-9_-]/i', '_', $tenantCode);
    }
}
