<?php

namespace App\Repository;

use App\Services\TenantConnectionManager;

/**
 * Historique des synchronisations de la configuration des landing pages (base maître, table landing_config_sync) :
 * donnée de plateforme, commune à tous les tenants.
 */
class LandingConfigSyncRepository
{
    public function __construct(private readonly TenantConnectionManager $connectionManager)
    {
    }

    /**
     * @param string $action sync | rollback
     * @param string $result activated | up_to_date | rolled_back | rejected_hash | rejected_compositions | error
     */
    public function add(string $action, ?string $version, string $author, string $result, array $details = []): void
    {
        $this->connectionManager->getPdoMaster()
            ->prepare('INSERT INTO landing_config_sync (created_at, action, version, author, result, details) VALUES (NOW(), ?, ?, ?, ?, ?)')
            ->execute([$action, $version !== null ? mb_substr($version, 0, 100) : null, mb_substr($author, 0, 255), $result, $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]);
    }

    /** @return list<array{date: string, action: string, version: ?string, author: string, result: string, details: mixed}> */
    public function latest(int $limit = 20): array
    {
        $statement = $this->connectionManager->getPdoMaster()->prepare('SELECT created_at, action, version, author, result, details FROM landing_config_sync ORDER BY id DESC LIMIT ?');
        $statement->bindValue(1, $limit, \PDO::PARAM_INT);
        $statement->execute();

        return array_map(fn (array $row) => [
            'date' => (new \DateTimeImmutable($row['created_at']))->format(\DateTimeInterface::ATOM),
            'action' => $row['action'],
            'version' => $row['version'],
            'author' => $row['author'],
            'result' => $row['result'],
            'details' => $row['details'] !== null ? json_decode($row['details'], true) : null,
        ], $statement->fetchAll(\PDO::FETCH_ASSOC));
    }
}
