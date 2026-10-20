<?php

namespace App\UseCase\AppKeyUseCase;

use App\Services\AppKeyService\AppKeyService;

/** POST /api/app/activate : de la clé saisie au site (code, nom, hôte complet pour X-Tenant-Host) */
class ActivateAppUseCase
{
    public function __construct(
        private readonly AppKeyService $keys,
        private readonly \App\Services\TenantConnectionManager $tenants,
        private readonly \App\Services\TenantEntityManagerProvider $emProvider,
        private readonly \App\Services\AppProfileService\AppProfileService $profiles,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {}

    /** @param array<string, mixed> $data corps JSON {key, app?, deviceName?} */
    public function execute(array $data, string $host = '', string $locale = 'fr'): array
    {
        $device = isset($data['deviceName']) && is_scalar($data['deviceName']) ? trim((string) $data['deviceName']) : null;
        $result = $this->keys->activate((string) ($data['key'] ?? ''), isset($data['app']) && is_scalar($data['app']) ? (string) $data['app'] : null, $device !== '' ? $device : null);

        return $result + ['profile' => $this->profile($result['tenant']['code'], (string) $result['app'], $host, $locale)];
    }

    /**
     * Profil de l'application dans le site de la clé (20/10/2026) : nom et logo dès le premier lancement, avant la
     * connexion ; null si le site ne peut pas être lu (l'activation reste valable, GET /api/app-profile/{app} suffit)
     */
    private function profile(string $tenantCode, string $app, string $host, string $locale): ?array
    {
        if (!\App\Services\AppProfileService\AppProfileService::isKnown($app)) {
            return null;
        }
        try {
            $tenant = $this->tenants->findTenantByCode($tenantCode);
            if (!$tenant || empty($tenant['dbname'])) {
                return null;
            }
            $this->emProvider->switchTenant($tenant['dbname'], $tenantCode);

            return $this->profiles->resolve($app, $host, $locale);
        } catch (\Throwable $e) {
            $this->logger->warning('Activation : profil de l\'application non lu : ' . $e->getMessage());

            return null;
        }
    }
}
