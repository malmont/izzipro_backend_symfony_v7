<?php

namespace App\UseCase\GestionUseCase;

use App\Services\GestionService\GestionDemoSeeder;
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
    ) {
    }

    /**
     * @return list<string> éléments ajoutés
     * @throws \InvalidArgumentException site non autorisé ou introuvable
     */
    public function execute(string $tenantCode): array
    {
        if (!in_array($tenantCode, self::ALLOWED_TENANTS, true)) {
            throw new \InvalidArgumentException(sprintf('Site « %s » refusé : le jeu de données de gestion ne se pose que sur %s.', $tenantCode, implode(', ', self::ALLOWED_TENANTS)));
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
