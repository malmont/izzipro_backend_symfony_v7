<?php

namespace App\UseCase\AppMembershipUseCase;

use App\Services\AppMembershipService\AppMembershipException;
use App\Services\AppMembershipService\AppMembershipService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;

/** `app:members:mode <site> [observe|require]` : lit ou règle le mode du contrôle d'appartenance d'un site (lot APPS-MEMBRES M3) */
class AppMembershipModeUseCase
{
    public function __construct(private readonly TenantConnectionManager $connectionManager, private readonly TenantEntityManagerProvider $emProvider, private readonly AppMembershipService $memberships)
    {
    }

    /**
     * @param ?string $mode null : lecture seule
     *
     * @return array{site: string, mode: string, changed: bool, backfillDone: bool}
     *
     * @throws \InvalidArgumentException site introuvable
     * @throws AppMembershipException mode inconnu, reprise pas faite, site sans fiche Entreprise
     */
    public function execute(string $site, ?string $mode): array
    {
        $statement = $this->connectionManager->getPdoMaster()->prepare('SELECT dbname FROM tenants WHERE code = :code');
        $statement->execute(['code' => $site]);
        $dbname = $statement->fetchColumn();
        if (!is_string($dbname) || $dbname === '') {
            throw new \InvalidArgumentException(sprintf('Site « %s » absent de la table tenants.', $site));
        }
        $this->emProvider->switchTenant($dbname, $site);
        $changed = $mode !== null && $this->memberships->changeMode($mode);

        return ['site' => $site, 'mode' => $this->memberships->mode(), 'changed' => $changed, 'backfillDone' => $this->memberships->backfillDone()];
    }
}
