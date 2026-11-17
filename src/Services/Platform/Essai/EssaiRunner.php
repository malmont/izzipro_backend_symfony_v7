<?php

namespace App\Services\Platform\Essai;

use App\Gestion\UseCase\SeedGestionDemoUseCase;
use App\Services\Platform\TestSites;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\BoutiqueDemoUseCase\SeedBoutiqueDemoUseCase;

/**
 * Le remplissage d'un site d'essai (lot ESSAI-DONNÉES), partagé par la commande `app:essai:remplir` et le worker `media` (bouton du web) :
 * verrou relu dans la base maître (`TestSites` : demo, ou un site d'une agence de test), puis le jeu de la boutique, celui de la gestion et
 * les données fictives (`TestSiteDataSeeder`). Rejouable, n'efface rien.
 */
class EssaiRunner
{
    public function __construct(
        private readonly TestSites $testSites,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly SeedBoutiqueDemoUseCase $boutique,
        private readonly SeedGestionDemoUseCase $gestion,
        private readonly TestSiteDataSeeder $seeder,
    ) {
    }

    public function allowed(string $tenantCode): bool
    {
        return $tenantCode !== '' && $this->testSites->allowed($tenantCode);
    }

    /**
     * @return list<string> le compte rendu, par partie
     * @throws \InvalidArgumentException site refusé ou inconnu (rien n'est écrit)
     */
    public function run(string $tenantCode, bool $dryRun = false): array
    {
        if (!$this->allowed($tenantCode)) {
            throw new \InvalidArgumentException(sprintf('Site « %s » refusé : seulement demo ou un site d\'une agence de test. Rien n\'est écrit.', $tenantCode));
        }
        $statement = $this->tenants->getPdoMaster()->prepare('SELECT dbname FROM tenants WHERE code = :code');
        $statement->execute(['code' => $tenantCode]);
        $dbname = $statement->fetchColumn();
        if (!is_string($dbname) || $dbname === '') {
            throw new \InvalidArgumentException(sprintf('Site « %s » absent de la table tenants.', $tenantCode));
        }
        $report = [];
        if (!$dryRun) {
            foreach ($this->boutique->execute($tenantCode, 'client.demo@example.invalid')['report'] ?: ['rien à ajouter'] as $line) {
                $report[] = 'Boutique : ' . $line;
            }
            foreach ($this->gestion->execute($tenantCode) ?: ['rien à ajouter'] as $line) {
                $report[] = 'Gestion : ' . $line;
            }
        }
        $this->emProvider->switchTenant($dbname, $tenantCode);

        return [...$report, ...$this->seeder->seed($dryRun)];
    }
}
