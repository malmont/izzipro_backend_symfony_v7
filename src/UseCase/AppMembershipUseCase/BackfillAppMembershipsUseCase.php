<?php

namespace App\UseCase\AppMembershipUseCase;

use App\Services\AppMembershipService\AppMembershipBackfill;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;

/**
 * `app:members:backfill <site>` : reprise de l'historique des appartenances d'un site (lot APPS-MEMBRES M2, `docs/apps-membres.md`
 * § 8). Simulation par défaut : aucune écriture. Le rapport ne porte que des nombres ; le détail compte par compte (identifiants,
 * jamais de nom ni de courriel) est écrit dans un fichier du serveur, hors dépôt.
 */
class BackfillAppMembershipsUseCase
{
    public function __construct(
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly AppMembershipBackfill $backfill,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{site: string, mode: string, report: array<string, mixed>, created: ?array<string, int>, detail: string}
     *
     * @throws \InvalidArgumentException site introuvable
     */
    public function execute(string $site, bool $apply): array
    {
        $statement = $this->connectionManager->getPdoMaster()->prepare('SELECT dbname FROM tenants WHERE code = :code');
        $statement->execute(['code' => $site]);
        $dbname = $statement->fetchColumn();
        if (!is_string($dbname) || $dbname === '') {
            throw new \InvalidArgumentException(sprintf('Site « %s » absent de la table tenants.', $site));
        }
        $this->emProvider->switchTenant($dbname, $site);
        $result = $apply ? $this->backfill->apply() : $this->backfill->plan();
        $mode = $apply ? 'apply' : 'simulation';
        $directory = $this->projectDir . '/var/members-backfill';
        if (!is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        $detail = sprintf('%s/%s-%s-%s.json', $directory, preg_replace('/[^a-z0-9_-]/i', '_', $site), (new \DateTimeImmutable())->format('Ymd-His'), $mode);
        file_put_contents($detail, json_encode(['site' => $site, 'mode' => $mode, 'report' => $result['report'], 'created' => $result['created'] ?? null, 'proposals' => $result['proposals']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return ['site' => $site, 'mode' => $mode, 'report' => $result['report'], 'created' => $result['created'] ?? null, 'detail' => $detail];
    }
}
