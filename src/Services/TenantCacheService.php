<?php
namespace App\Services;

use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use App\Services\TenantConnectionProvider;

class TenantCacheService
{
    public function __construct(
        private TagAwareCacheInterface $cache,
        private TenantConnectionProvider $tcp
    ) {}

    // Pour les CLÉS de cache
    private function getTenantPrefix(): string
    {
        $tenantCode = $this->tcp->getTenantCode() ?: 'master';
        return $tenantCode . ':';
    }

    // Pour les TAGS de cache (pas de caractère interdit !)
    private function getTenantTag(): string
    {
        return $this->tcp->getTenantCode() ?: 'master';
    }

    /**
     * Récupère ou calcule un item, isolé par tenant.
     */
    public function get(
        string $keySuffix,
        callable $compute,
        ?int $ttl = null,
        ?array $extraTags = null
    ): mixed {
        $key = $this->getTenantPrefix() . $keySuffix;

        // Tag principal = tenant tag, jamais de caractère interdit
        $tags = array_merge([$this->getTenantTag()], $extraTags ?? []);

        return $this->cache->get($key, function(ItemInterface $item) use ($compute, $ttl, $tags) {
            if ($ttl !== null) {
                $item->expiresAfter($ttl);
            }
            $item->tag($tags);
            return $compute($item);
        });
    }

    /**
     * Invalide le cache d'un tenant pour une clé spécifique (optionnel).
     */
    public function invalidate(string $keySuffix, ?array $extraTags = null): void
    {
        $key = $this->getTenantPrefix() . $keySuffix;
        $this->cache->delete($key);

        if ($this->cache instanceof TagAwareCacheInterface) {
            $tags = array_merge([$this->getTenantTag()], $extraTags ?? []);
            $this->cache->invalidateTags($tags);
        }
    }
}
