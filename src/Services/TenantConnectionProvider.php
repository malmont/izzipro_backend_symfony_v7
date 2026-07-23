<?php

namespace App\Services;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Configuration;
use Doctrine\Common\EventManager;
use ReflectionProperty;

class TenantConnectionProvider
{
    private ?Connection $connection = null;
    private array $baseParams;
    private Configuration $config;
    private ?EventManager $eventManager = null;
    private ?string $tenantCode = null;

    public function __construct(private Connection $defaultConnection)
    {
        $this->baseParams = $defaultConnection->getParams();
        $this->config = $defaultConnection->getConfiguration();
        if (method_exists($defaultConnection, 'getEventManager')) {
            $this->eventManager = $defaultConnection->getEventManager();
        }

        if (!isset($this->baseParams['serverVersion'])) {
            $this->baseParams['serverVersion'] = '14';
        }
    }

    public function switchTenant(string $tenantDbName, ?string $tenantCode = null): void
    {
        $currentParams = $this->defaultConnection->getParams();
        if (($currentParams['dbname'] ?? null) === $tenantDbName && $this->tenantCode === $tenantCode) {
            return;
        }

        if ($this->defaultConnection->isConnected()) {
            $this->defaultConnection->close();
        }

        // Basculer dynamiquement le nom de la base de données de la connexion Doctrine par défaut
        $refParams = new ReflectionProperty(Connection::class, 'params');
        $params = $refParams->getValue($this->defaultConnection);
        $params['dbname'] = $tenantDbName;
        $refParams->setValue($this->defaultConnection, $params);

        $refConn = new ReflectionProperty(Connection::class, '_conn');
        $refConn->setValue($this->defaultConnection, null);

        $this->tenantCode = $tenantCode;
        $this->connection = $this->defaultConnection;
    }

    public function getConnection(): Connection
    {
        if (!$this->connection) {
            $this->connection = $this->defaultConnection;
        }
        return $this->connection;
    }

    public function getTenantCode(): ?string
    {
        return $this->tenantCode;
    }
}
