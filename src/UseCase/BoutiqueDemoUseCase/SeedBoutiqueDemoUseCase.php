<?php

namespace App\UseCase\BoutiqueDemoUseCase;

use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;

/**
 * Remplit la boutique de démonstration. Réservé aux sites de test (ALLOWED_TENANTS) : le site est lu dans la table
 * tenants de la base maître, jamais écrit en dur ailleurs, et un site client est refusé.
 */
class SeedBoutiqueDemoUseCase
{
    public const ALLOWED_TENANTS = ['demo'];

    public function __construct(
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly BoutiqueDemoSeeder $seeder,
        private readonly ?\App\Services\Platform\TestSites $testSites = null,
    ) {
    }

    /**
     * @return array{report: list<string>, customerPassword: ?string}
     * @throws \InvalidArgumentException site non autorisé ou introuvable
     */
    public function execute(string $tenantCode, string $customerEmail, bool $resetPassword = false, bool $otp = false): array
    {
        // lot ESSAI-DONNÉES : demo, ou un site d'une agence de test (base maître, relue ici) ; jamais un site client
        if (!in_array($tenantCode, self::ALLOWED_TENANTS, true) && !($this->testSites?->allowed($tenantCode) ?? false)) {
            throw new \InvalidArgumentException(sprintf('Site « %s » refusé : la boutique de démonstration ne se remplit que sur %s et les sites d\'une agence de test.', $tenantCode, implode(', ', self::ALLOWED_TENANTS)));
        }
        $statement = $this->connectionManager->getPdoMaster()->prepare('SELECT dbname FROM tenants WHERE code = :code');
        $statement->execute(['code' => $tenantCode]);
        $dbname = $statement->fetchColumn();
        if (!is_string($dbname) || $dbname === '') {
            throw new \InvalidArgumentException(sprintf('Site « %s » absent de la table tenants.', $tenantCode));
        }
        $this->emProvider->switchTenant($dbname, $tenantCode);

        return $this->seeder->seed($customerEmail, $resetPassword, $otp);
    }
}
