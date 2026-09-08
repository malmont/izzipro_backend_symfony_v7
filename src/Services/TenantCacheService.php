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

    private function getTenantPrefix(): string
    {
        return ($this->tcp->getTenantCode() ?: 'master') . '.';
    }

    private function getTenantCode(): string
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
        $rawKey = $this->getTenantPrefix() . $keySuffix;
        $key = preg_replace('/[{}()\/\\\\@:]/', '_', $rawKey);

        $tenantCode = $this->getTenantCode();
        $tags = [];
        if ($extraTags) {
            $tags = array_map(fn($tag) => preg_replace('/[{}()\/\\\\@:]/', '_', $tenantCode . '_' . $tag), $extraTags);
        }
        $tags[] = preg_replace('/[{}()\/\\\\@:]/', '_', $tenantCode);

        return $this->cache->get($key, function (ItemInterface $item) use ($compute, $ttl, $tags) {
            if ($ttl !== null) {
                $item->expiresAfter($ttl);
            }
            $item->tag($tags);
            return $compute($item);
        });
    }

    public function delete(string $keySuffix): bool
    {
        $rawKey = $this->getTenantPrefix() . $keySuffix;
        $key = preg_replace('/[{}()\/\\\\@:]/', '_', $rawKey);
        return $this->cache->delete($key);
    }
}
