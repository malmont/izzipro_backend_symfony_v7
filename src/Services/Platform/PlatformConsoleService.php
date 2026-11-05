<?php

namespace App\Services\Platform;

use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\TenantConnectionManager;

/** Vue d'ensemble de la plateforme (console de pilotage, lot 4, 04/11/2026) : sites × fonctionnalités gouvernées, demandes en attente */
class PlatformConsoleService
{
    public function __construct(private readonly TenantConnectionManager $tenants, private readonly EntitlementService $entitlements, private readonly FeatureRequestRepository $requests, private readonly FeaturePolicyRepository $policies, private readonly PlatformOfferRepository $offers, private readonly SiteReadinessService $readiness)
    {
    }

    /**
     * @return list<array{code: string, name: string, dbname: string, features: array<string, array<string, mixed>>, pendingRequests: list<string>}>
     *   un site par code (deux codes peuvent partager une base), modèle `gmasuite` exclu
     */
    public function grid(): array
    {
        $pending = [];
        foreach ($this->requests->listPending() as $r) {
            $pending[$r['tenant_code']][] = $r['feature_key'];
        }
        $rows = [];
        foreach ($this->tenants->getAllTenants() as $tenant) {
            $code = (string) $tenant['code'];
            if ($code === 'gmasuite') {
                continue;
            }
            $readiness = $this->readiness->forSite($code);
            $rows[] = ['code' => $code, 'name' => (string) $tenant['name'], 'dbname' => (string) $tenant['dbname'], 'features' => $this->entitlements->effective($code), 'pendingRequests' => $pending[$code] ?? [],
                'readiness' => ['percent' => $readiness['percent'], 'done' => $readiness['done'], 'total' => $readiness['total'], 'next' => $readiness['next'] ? ['key' => $readiness['next']['key'], 'title' => $readiness['next']['title'], 'where' => $readiness['next']['where']] : null]];
        }

        return $rows;
    }

    /** @return list<string> codes des sites (hors modèle) */
    public function tenantCodes(): array
    {
        return array_values(array_filter(array_map(fn ($t) => (string) $t['code'], $this->tenants->getAllTenants()), fn ($c) => $c !== 'gmasuite'));
    }

    public function tenantExists(string $code): bool
    {
        return is_array($this->tenants->findTenantByCode($code));
    }

    /** @return array<string, array<string, mixed>> */
    public function policies(): array
    {
        return $this->policies->all(array_keys(FeatureCatalog::GOVERNED));
    }

    /** @return list<array<string, mixed>> */
    public function offers(): array
    {
        return $this->offers->all(false);
    }
}
