<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\GestionDemoSeeder;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;

/** `app:gestion:seed-demo` : jeu de données de gestion, réservé au site de démonstration (comme la boutique de démonstration) */
class SeedGestionDemoUseCase
{
    public const ALLOWED_TENANTS = ['demo'];

    public function __construct(
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly GestionDemoSeeder $seeder,
        private readonly ?\App\Services\Platform\TestSites $testSites = null,
    ) {
    }

    /**
     * @return list<string> éléments ajoutés
     * @throws \InvalidArgumentException site non autorisé ou introuvable
     */
    public function execute(string $tenantCode): array
    {
        // lot ESSAI-DONNÉES : demo, ou un site d'une agence de test (base maître, relue ici) ; jamais un site client
        if (!in_array($tenantCode, self::ALLOWED_TENANTS, true) && !($this->testSites?->allowed($tenantCode) ?? false)) {
            throw new \InvalidArgumentException(sprintf('Site « %s » refusé : le jeu de données de gestion ne se pose que sur %s et les sites d\'une agence de test.', $tenantCode, implode(', ', self::ALLOWED_TENANTS)));
        }
        $statement = $this->connectionManager->getPdoMaster()->prepare('SELECT dbname FROM tenants WHERE code = :code');
        $statement->execute(['code' => $tenantCode]);
        $dbname = $statement->fetchColumn();
        if (!is_string($dbname) || $dbname === '') {
            throw new \InvalidArgumentException(sprintf('Site « %s » absent de la table tenants.', $tenantCode));
        }
        $this->emProvider->switchTenant($dbname, $tenantCode);

        return $this->seeder->seed();
    }
}
