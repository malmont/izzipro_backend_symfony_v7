<?php

namespace App\UseCase\AppMembershipUseCase;

use App\Services\AppMembershipService\AppFamilySplit;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;

/**
 * `app:members:family-split <site>` : reclassement des appartenances de l'ancienne famille `memoires` et promotion en biographes des
 * comptes clients propriétaires d'un livre web (`AppFamilySplit`). Simulation par défaut : aucune écriture. Le rapport ne porte que
 * des nombres ; le détail compte par compte (identifiants seulement) est écrit dans un fichier du serveur, hors dépôt.
 */
class SplitAppFamilyUseCase
{
    public function __construct(
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly AppFamilySplit $split,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{site: string, mode: string, report: array<string, mixed>, done: ?array<string, int>, detail: string}
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
        $result = $apply ? $this->split->apply() : $this->split->plan();
        $mode = $apply ? 'apply' : 'simulation';
        $directory = $this->projectDir . '/var/members-backfill';
        if (!is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        $detail = sprintf('%s/%s-%s-family-split-%s.json', $directory, preg_replace('/[^a-z0-9_-]/i', '_', $site), (new \DateTimeImmutable())->format('Ymd-His'), $mode);
        file_put_contents($detail, json_encode(['site' => $site, 'mode' => $mode, 'report' => $result['report'], 'done' => $result['done'] ?? null, 'operations' => $result['operations']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return ['site' => $site, 'mode' => $mode, 'report' => $result['report'], 'done' => $result['done'] ?? null, 'detail' => $detail];
    }
}
