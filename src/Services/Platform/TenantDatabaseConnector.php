<?php

namespace App\Services\Platform;

use App\Services\TenantCacheService;
use App\Services\TenantConnectionManager;

/**
 * Connexion directe à la base d'un site depuis la plateforme (console de pilotage, lot 3, 04/11/2026) : la connexion courante n'est
 * pas touchée (même mécanique que SuperAdminAccountService). Sert à projeter les droits d'usage sur la fiche Entreprise d'un autre
 * site et à vider son cache. Identifiants : ceux de la connexion des sites (jamais rendus).
 */
class TenantDatabaseConnector
{
    public function __construct(private readonly TenantConnectionManager $tenants, private readonly TenantCacheService $cache)
    {
    }

    /** @return array{code: string, dbname: string, name: string}|null */
    public function tenant(string $code): ?array
    {
        $t = $this->tenants->findTenantByCode($code);

        return is_array($t) && !empty($t['dbname']) ? ['code' => (string) ($t['code'] ?? $code), 'dbname' => (string) $t['dbname'], 'name' => (string) ($t['name'] ?? $code)] : null;
    }

    public function pdo(string $dbname): \PDO
    {
        $p = $this->tenants->getTenantParams();

        return new \PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'] ?? 'localhost', (int) ($p['port'] ?? 5432), $dbname), (string) ($p['user'] ?? ''), (string) ($p['password'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]);
    }

    /** Vide tout le cache du site (étiquettes du code et de la base) : une écriture SQL directe ne passe pas par CacheInvalidationSubscriber */
    public function invalidateSiteCache(string $code, string $dbname): void
    {
        try {
            $this->cache->invalidateSite($code, $dbname);
        } catch (\Throwable) {
        }
    }
}
