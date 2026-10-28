<?php

namespace App\Cache;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Étiquettes à vider, en attente par base, jusqu'à la validation réelle de la transaction (lot 1, 28/10/2026).
 *
 * CacheInvalidationSubscriber y dépose les étiquettes au flush ; le middleware DBAL (Cache\Doctrine\InvalidateOnCommit*)
 * les vide au COMMIT réel de la connexion (niveau d'imbrication revenu à zéro) et les abandonne au ROLLBACK (rien n'a
 * été écrit, le cache reste juste). Avant : vidage juste après le flush, donc avant la validation dans une transaction
 * explicite ; une lecture concurrente pouvait remettre l'ancienne valeur en cache. Le second passage en fin de requête,
 * de commande ou de message (CacheInvalidationFinalizer → flushAll) est conservé en filet.
 */
final class CacheInvalidationQueue
{
    /** @var array<string, array<string, string>> étiquettes complètes en attente, par base */
    private array $pending = [];

    public function __construct(private TagAwareCacheInterface $cache, private LoggerInterface $logger)
    {
    }

    /** @param list<string> $tags étiquettes complètes (TenantCacheService::componentTag, tenantTag, databaseTag) */
    public function add(string $dbname, array $tags): void
    {
        foreach ($tags as $tag) {
            $this->pending[$dbname][$tag] = $tag;
        }
    }

    /** Validation réelle d'une connexion : vide ce qui attendait pour sa base */
    public function commit(string $dbname): void
    {
        if (!isset($this->pending[$dbname])) {
            return;
        }
        $tags = $this->pending[$dbname];
        unset($this->pending[$dbname]);
        $this->invalidate($tags);
    }

    /** Annulation : rien n'a été écrit, le cache reste juste */
    public function rollBack(string $dbname): void
    {
        unset($this->pending[$dbname]);
    }

    /** Filet de fin de requête, de commande ou de message : vide tout ce qui attend encore */
    public function flushAll(): void
    {
        $tags = [];
        foreach ($this->pending as $pendingTags) {
            $tags += $pendingTags;
        }
        $this->pending = [];
        if ($tags !== []) {
            $this->invalidate($tags);
        }
    }

    /** @param array<string, string> $tags */
    private function invalidate(array $tags): void
    {
        try {
            $this->cache->invalidateTags(array_values($tags));
        } catch (\Throwable $e) {
            // Redis indisponible : l'écriture est faite, le cache expirera de lui-même ; ne pas faire échouer la requête
            $this->logger->warning('Cache : invalidation impossible, les entrées expireront d\'elles-mêmes', [
                'tags' => array_values($tags),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
