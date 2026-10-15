<?php

namespace App\Services;

/**
 * Hôte public d'un site, celui que les applications envoient dans X-Tenant-Host (15/10/2026) : son domaine propre
 * (custom_domain de la table tenants, base maître, lecture seule), sinon <code>.<FRONTEND_BASE_DOMAIN>. Plusieurs
 * contrôleurs construisent les liens des e-mails avec cette valeur : un code nu y donnerait des liens faux.
 * Utilisé par GET /api/tenant/check-mobile et par l'activation des applications (POST /api/app/activate).
 */
class TenantPublicHostResolver
{
    public function __construct(private readonly TenantConnectionManager $tenantManager, private readonly string $frontendBaseDomain)
    {
    }

    /** @return array{code: string, name: string, host: string}|null */
    public function describe(string $code): ?array
    {
        $stmt = $this->tenantManager->getPdoMaster()->prepare('SELECT code, name, custom_domain FROM tenants WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $customDomain = trim((string) ($row['custom_domain'] ?? ''));

        return [
            'code' => (string) $row['code'],
            'name' => (string) ($row['name'] ?? $row['code']),
            'host' => $customDomain !== '' ? $customDomain : $row['code'] . '.' . $this->frontendBaseDomain,
        ];
    }
}
