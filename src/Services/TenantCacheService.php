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

    /** Étiquette d'un site, posée sur chacune de ses entrées */
    public static function tenantTag(string $tenantCode): string
    {
        return preg_replace('/[{}()\/\\\\@:]/', '_', $tenantCode);
    }

    /**
     * Étiquette de la base, posée elle aussi sur chaque entrée (26/10/2026) : deux codes de site peuvent partager une
     * base (esgboost et esgboost1, arkanoa-media et arkanoa-media1), et une commande peut écrire sans code de site.
     * Une écriture vide l'étiquette du code et celle de la base (CacheInvalidationSubscriber).
     */
    public static function databaseTag(string $dbname): string
    {
        return 'db.' . preg_replace('/[{}()\/\\\\@:]/', '_', $dbname);
    }

    private function getDatabaseName(): ?string
    {
        $dbname = $this->tcp->getConnection()->getParams()['dbname'] ?? null;

        return is_string($dbname) && $dbname !== '' ? $dbname : null;
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
        $tags[] = self::tenantTag($tenantCode);
        if ($dbname = $this->getDatabaseName()) {
            $tags[] = self::databaseTag($dbname);
        }

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

    /**
     * Invalide les caches associés à des tags donnés pour le tenant actuel.
     *
     * @param string[] $extraTags
     */
    public function invalidateTags(array $extraTags): bool
    {
        $tenantCode = $this->getTenantCode();
        $tags = array_map(fn($tag) => preg_replace('/[{}()\/\\\\@:]/', '_', $tenantCode . '_' . $tag), $extraTags);
        return $this->cache->invalidateTags($tags);
    }

    /**
     * Invalide tout le cache du tenant actuel et de sa base (chaque entrée porte ces deux étiquettes) : après une
     * écriture en masse ou en SQL direct
     */
    public function invalidateTenant(): bool
    {
        $tags = [self::tenantTag($this->getTenantCode())];
        if ($dbname = $this->getDatabaseName()) {
            $tags[] = self::databaseTag($dbname);
        }

        return $this->cache->invalidateTags($tags);
    }
}
