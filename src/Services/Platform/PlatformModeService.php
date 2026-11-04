<?php

namespace App\Services\Platform;

use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Mode de la plateforme (console de pilotage, lot 1 bis, 04/11/2026), table maître `platform_settings`, clé `mode` :
 * - `demo` (défaut, et valeur retenue si la table ou la ligne manque) : aucune restriction de module ni de droit d'usage ne
 *   bloque un service ; tout reste visible et réglable dans la console (essais du propriétaire comme avant la console) ;
 * - `live` : les garde-fous s'appliquent (ModuleAccessSubscriber, puis les droits d'usage du lot 3).
 * Réglé par le super administrateur (PUT /api/platform/settings, onglet « Plateforme »). Lu à chaque requête d'API gardée :
 * cache partagé 60 s, vidé à l'écriture.
 */
class PlatformModeService
{
    public const DEMO = 'demo';
    public const LIVE = 'live';
    public const MODES = [self::DEMO, self::LIVE];
    private const CACHE_KEY = 'platform.settings.mode';
    private const TTL = 60;

    public function __construct(private readonly TenantConnectionManager $tenants, private readonly CacheInterface $cache, private readonly LoggerInterface $logger)
    {
    }

    public function mode(): string
    {
        try {
            return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): string {
                $item->expiresAfter(self::TTL);

                return $this->read();
            });
        } catch (\Throwable $e) {
            $this->logger->warning('PlatformModeService : mode illisible, demo retenu : ' . $e->getMessage());

            return self::DEMO;
        }
    }

    public function isDemo(): bool
    {
        return $this->mode() === self::DEMO;
    }

    /** @return array{mode: string, updatedAt: ?string, updatedBy: ?string} */
    public function settings(): array
    {
        try {
            $row = $this->tenants->getPdoMaster()->query("SELECT value, updated_at, updated_by FROM platform_settings WHERE key = 'mode'")->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            $row = false;
        }

        return ['mode' => $row && in_array($row['value'], self::MODES, true) ? $row['value'] : self::DEMO, 'updatedAt' => $row['updated_at'] ?? null, 'updatedBy' => $row['updated_by'] ?? null];
    }

    public function setMode(string $mode, ?string $by): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('mode : demo ou live attendu');
        }
        $stmt = $this->tenants->getPdoMaster()->prepare("INSERT INTO platform_settings (key, value, updated_at, updated_by) VALUES ('mode', ?, NOW(), ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW(), updated_by = EXCLUDED.updated_by");
        $stmt->execute([$mode, $by]);
        $this->cache->delete(self::CACHE_KEY);
        $this->logger->notice(sprintf('Mode de la plateforme : %s (par %s)', $mode, $by ?? 'inconnu'));
    }

    private function read(): string
    {
        $value = $this->tenants->getPdoMaster()->query("SELECT value FROM platform_settings WHERE key = 'mode'")->fetchColumn();

        return is_string($value) && in_array($value, self::MODES, true) ? $value : self::DEMO;
    }
}
