<?php

namespace App\Services\Platform;

use App\Services\TenantConnectionManager;

/**
 * Les INTERRUPTEURS du commerçant (lot MOD-1, 16/11/2026), base maître, table `tenant_feature_switches` : un module auquel le site a
 * droit, allumé ou éteint par son administrateur. Ligne absente : allumé.
 */
class FeatureSwitchRepository
{
    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    /** @return array<string, bool> clé => allumé, pour les seules clés réglées (les autres sont allumées) */
    public function forTenant(string $tenantCode): array
    {
        $stmt = $this->tenants->getPdoMaster()->prepare('SELECT feature_key, enabled FROM tenant_feature_switches WHERE tenant_code = ?');
        $stmt->execute([$tenantCode]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(string) $row['feature_key']] = in_array($row['enabled'], [true, 't', 'true', 1, '1'], true);
        }

        return $out;
    }

    public function set(string $tenantCode, string $featureKey, bool $enabled, ?string $by): void
    {
        $this->tenants->getPdoMaster()->prepare('INSERT INTO tenant_feature_switches (tenant_code, feature_key, enabled, updated_at, updated_by) VALUES (?, ?, ?, NOW(), ?)
            ON CONFLICT (tenant_code, feature_key) DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = NOW(), updated_by = EXCLUDED.updated_by')
            ->execute([$tenantCode, $featureKey, $enabled ? 't' : 'f', $by !== null ? mb_substr($by, 0, 255) : null]);
    }
}
