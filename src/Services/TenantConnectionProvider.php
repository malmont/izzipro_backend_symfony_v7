<?php

namespace App\Services;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Configuration;
use Doctrine\Common\EventManager;


class TenantConnectionProvider
{
    private ?Connection $connection = null;
    private array $baseParams;
    private Configuration $config;
    private EventManager $eventManager;
    private ?string $tenantCode = null;

    public function __construct(Connection $defaultConnection)
    {
        $this->baseParams = $defaultConnection->getParams();
        $this->config = $defaultConnection->getConfiguration();
        $this->eventManager = $defaultConnection->getEventManager();

        // Ensure serverVersion is always set so Doctrine uses the correct
        // PostgreSQL ID generation strategy (sequences) in all environments.
        // Without this, production deployments without ?serverVersion= in
        // DATABASE_URL cause Doctrine to omit the ID in INSERT statements.
        if (!isset($this->baseParams['serverVersion'])) {
            $this->baseParams['serverVersion'] = '14';
        }
    }
    // Ferme la connexion active et en initialise une nouvelle pointant vers la base de données cible.
    public function switchTenant(string $tenantDbName, ?string $tenantCode = null): void
    {
        if ($this->connection && $this->connection->isConnected()) {
            $this->connection->close();
        }
        $params = $this->baseParams;
        $params['dbname'] = $tenantDbName;
        $this->tenantCode = $tenantCode;
        $this->connection = DriverManager::getConnection($params, $this->config, $this->eventManager);
    }
    // Retourne la connexion active ou l'initialise si nécessaire (Lazy Loading).
    public function getConnection(): Connection
    {
        if (!$this->connection) {
            $this->connection = DriverManager::getConnection($this->baseParams, $this->config, $this->eventManager);
        }
        return $this->connection;
    }
    public function getTenantCode(): ?string
    {
        return $this->tenantCode;
    }
}
