<?php

namespace App\Tests\Unit\Service;

use App\Services\TenantConnectionProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Configuration;
use Doctrine\Common\EventManager;
use PHPUnit\Framework\TestCase;

class TenantConnectionProviderTest extends TestCase
{
    public function testGetConnectionReturnsDerivedConnection(): void
    {
        // 1. Mock Default Connection dependencies
        $params = ['dbname' => 'default_db', 'user' => 'root', 'driver' => 'pdo_mysql'];
        $config = $this->createMock(Configuration::class);
        $eventManager = $this->createMock(EventManager::class);

        $defaultConnection = $this->createMock(Connection::class);
        $defaultConnection->method('getParams')->willReturn($params);
        $defaultConnection->method('getConfiguration')->willReturn($config);
        $defaultConnection->method('getEventManager')->willReturn($eventManager);

        // 2. Instantiate Provider
        $provider = new TenantConnectionProvider($defaultConnection);

        // 3. Execution (initial state)
        // Note: getConnection() implementation uses DriverManager::getConnection() which creates a NEW connection
        // We cannot easily mock DriverManager::getConnection as it is a static call.
        // However, we can verify the behavior if we could inspect the returned connection.
        // Since DriverManager returns a real Connection (or fails if drivers missing), unit testing this 
        // with real DriverManager might be tricky without a DB.
        // BUT, if we just want to verify logic:

        // Retrying approach: The class uses `DriverManager::getConnection`. 
        // This is a static call and hard to mock "cleanly" in pure unit tests without partial mocks or wrappers.
        // However, we can assert that we get a Connection object back and it has expected params.

        // For this test, we accept that DriverManager will attempt to create a connection.
        // We can pass a 'pdo_sqlite' memory driver to make it work without real DB?
        // Or just trust it returns a connection object.

        // Let's refine the mock params to be 'sqlite' :memory: so it actually works!
        $params = ['url' => 'sqlite:///:memory:'];
        $defaultConnection = $this->createMock(Connection::class);
        $defaultConnection->method('getParams')->willReturn($params);
        $defaultConnection->method('getConfiguration')->willReturn($config);
        $defaultConnection->method('getEventManager')->willReturn($eventManager);

        $provider = new TenantConnectionProvider($defaultConnection);
        $conn = $provider->getConnection();

        $this->assertInstanceOf(Connection::class, $conn);
        $this->assertEquals($params['url'], $conn->getParams()['url']);
    }

    public function testSwitchTenantCreatesNewConnectionWithUpdatedParams(): void
    {
        $params = ['dbname' => 'default_db', 'driver' => 'pdo_sqlite', 'memory' => true];
        $config = $this->createMock(Configuration::class);
        $eventManager = $this->createMock(EventManager::class);

        $defaultConnection = $this->createMock(Connection::class);
        $defaultConnection->method('getParams')->willReturn($params);
        $defaultConnection->method('getConfiguration')->willReturn($config);
        $defaultConnection->method('getEventManager')->willReturn($eventManager);

        $provider = new TenantConnectionProvider($defaultConnection);

        // Switch
        $provider->switchTenant('tenant_db', 'TENANT_1');

        // Verify Code
        $this->assertEquals('TENANT_1', $provider->getTenantCode());

        // Verify Connection updated
        $conn = $provider->getConnection();
        $this->assertInstanceOf(Connection::class, $conn);

        // The new connection should have the new dbname
        $this->assertEquals('tenant_db', $conn->getParams()['dbname']);
    }
}
