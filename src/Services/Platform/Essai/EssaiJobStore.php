<?php

namespace App\Services\Platform\Essai;

use App\Services\TenantConnectionManager;

/**
 * L'état du dernier remplissage d'un site d'essai demandé par le bouton du web (lot ESSAI-DONNÉES) : `queued`, `running`, `done`,
 * `failed`, avec qui l'a demandé, les heures et le compte rendu. Lu par l'API, écrit par le worker. Une seule exécution à la fois par site.
 *
 * Lot CACHE-ETAT (20/11/2026) : gardé en BASE MAÎTRE (`essai_job`, `ops/sql/cache_state_master.sql`), une ligne par site, relue 7 jours au
 * plus. Avant, dans le cache partagé : « Vider Redis » ou une montée de version effaçait l'état d'un remplissage en cours et le web
 * restait sur « en cours ».
 */
class EssaiJobStore
{
    public const TTL = 604800;
    /** Une exécution en attente ou en cours depuis plus longtemps est tenue pour perdue (worker arrêté) : on peut relancer */
    public const STALE_AFTER = 3600;

    public function __construct(private readonly TenantConnectionManager $tenants)
    {
    }

    /** @return array<string, mixed>|null */
    public function get(string $tenantCode): ?array
    {
        $stmt = $this->tenants->getPdoMaster()->prepare("SELECT state FROM essai_job WHERE tenant_code = ? AND updated_at > NOW() - make_interval(secs => ?)");
        $stmt->execute([$tenantCode, self::TTL]);
        $state = $stmt->fetchColumn();
        $state = is_string($state) ? json_decode($state, true) : null;

        return is_array($state) ? $state : null;
    }

    /** @param array<string, mixed> $state */
    public function put(string $tenantCode, array $state): void
    {
        $this->tenants->getPdoMaster()->prepare('INSERT INTO essai_job (tenant_code, state, updated_at) VALUES (?, CAST(? AS JSONB), NOW())
            ON CONFLICT (tenant_code) DO UPDATE SET state = EXCLUDED.state, updated_at = NOW()')
            ->execute([$tenantCode, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    }

    /** Oublie l'état d'un site (essais) */
    public function forget(string $tenantCode): void
    {
        $this->tenants->getPdoMaster()->prepare('DELETE FROM essai_job WHERE tenant_code = ?')->execute([$tenantCode]);
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
}
