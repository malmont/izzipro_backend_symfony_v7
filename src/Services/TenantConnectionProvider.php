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


    }

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
