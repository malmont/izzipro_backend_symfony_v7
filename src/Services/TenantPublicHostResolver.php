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

    /**
     * Hôtes publics de tous les sites (GET /api/tenant/hosts, 17/10/2026), pour que le frontend refuse les autres avant
     * le rendu : domaines personnalisés (sans « www. ») et <code>.<domaine du frontend>, plus ce domaine lui-même quand
     * son premier segment est un code de site. Même règle que TenantConnectionManager::findTenantConfigByHost (domaines
     * du backend et *.localhost exclus : le frontend ne les sert pas). Hôtes seulement, triés, sans doublon.
     *
     * @return list<string>
     */
    public function listPublicHosts(): array
    {
        $frontend = strtolower(trim($this->frontendBaseDomain, " .\"'"));
        $hosts = [];
        foreach ($this->tenantManager->getPdoMaster()->query('SELECT code, custom_domain FROM tenants')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $custom = strtolower(trim((string) ($row['custom_domain'] ?? '')));
            $hosts[] = str_starts_with($custom, 'www.') ? substr($custom, 4) : $custom;
            // Un trait bas est invalide dans un nom d'hôte : la résolution tient « - » et « _ » pour équivalents
            $code = str_replace('_', '-', strtolower((string) $row['code']));
            if ($frontend !== '') {
                $hosts[] = $code . '.' . $frontend;
                if ($code === explode('.', $frontend)[0]) {
                    $hosts[] = $frontend;
                }
            }
        }
        $hosts = array_unique(array_filter($hosts, static fn (string $h): bool => (bool) preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $h)));
        sort($hosts);

        return $hosts;
    }

    /** @return array{code: string, name: string, host: string}|null */
    /** @param bool $withShortCode true pour check-mobile seulement (31/10/2026) : l'activation des applications garde sa réponse d'avant */
    public function describe(string $code, bool $withShortCode = false): ?array
    {
        $stmt = $this->tenantManager->getPdoMaster()->prepare('SELECT code, name, custom_domain, short_code FROM tenants WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $customDomain = trim((string) ($row['custom_domain'] ?? ''));

        $tenant = [
            'code' => (string) $row['code'],
            'name' => (string) ($row['name'] ?? $row['code']),
            'host' => $customDomain !== '' ? $customDomain : $row['code'] . '.' . $this->frontendBaseDomain,
        ];
        if ($withShortCode) {
            // Code court (31/10/2026) : « DEM042 », null tant que le site n'en a pas (champ ajouté à check-mobile, le reste ne change pas)
            $tenant['shortCode'] = \is_string($row['short_code'] ?? null) && $row['short_code'] !== '' ? $row['short_code'] : null;
        }

        return $tenant;
    }
}
