<?php

namespace App\Tests\Unit\Service;

use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class TenantEntityManagerProviderTest extends TestCase
{
    /**
     * Helper pour créer un Partial Mock du serviceProvider.
     * On mock seulement 'createEntityManager' pour éviter d'instancier un vrai Doctrine EM.
     */
    private function createPartialMockProvider($connProvider, $config)
    {
        return $this->getMockBuilder(TenantEntityManagerProvider::class)
            ->setConstructorArgs([$connProvider, $config])
            ->onlyMethods(['createEntityManager']) // <-- C'est ici la magie
            ->getMock();
    }

    public function testGetEntityManagerCreatesNewInstanceWhenNoneExists(): void
    {
        // 1. Mocks Dependencies
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(false);
        $connection->expects($this->once())->method('connect');
        $connection->method('getDatabase')->willReturn('db_tenant_1');

        $connProvider = $this->createMock(TenantConnectionProvider::class);
        $connProvider->method('getConnection')->willReturn($connection);

        $config = $this->createMock(Configuration::class);

        // 2. Mock du résultat de la factory (Un faux EM)
        $mockEm = $this->createMock(EntityManagerInterface::class);

        // 3. Partial Mock du Service
        $provider = $this->createPartialMockProvider($connProvider, $config);

        // On s'attend à ce que la factory soit appelée UNE fois
        $provider->expects($this->once())
            ->method('createEntityManager')
            ->with($connection, $config)
            ->willReturn($mockEm);

        // 4. Execution
        $em = $provider->getEntityManager();

        // 5. Assertion
        $this->assertSame($mockEm, $em);
    }

    public function testGetEntityManagerReusesInstanceForSameDatabase(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('getDatabase')->willReturn('db_tenant_1');

        $connProvider = $this->createMock(TenantConnectionProvider::class);
        $connProvider->method('getConnection')->willReturn($connection);

        $config = $this->createMock(Configuration::class);
        $mockEm = $this->createMock(EntityManagerInterface::class);
        $mockEm->method('isOpen')->willReturn(true); // Important pour la réutilisation

        $provider = $this->createPartialMockProvider($connProvider, $config);

        // On s'attend à UNE SEULE création
        $provider->expects($this->once())
            ->method('createEntityManager')
            ->willReturn($mockEm);

        // 1. First call -> Creates
        $em1 = $provider->getEntityManager();

        // 2. Second call -> Reuses
        $em2 = $provider->getEntityManager();

        $this->assertSame($em1, $em2);
    }

    public function testSwitchTenantUpdatesConnectionProviderOnly(): void
    {
        $connProvider = $this->createMock(TenantConnectionProvider::class);
        $connProvider->expects($this->once())
            ->method('switchTenant')
            ->with('new_db', 'new_code');

        $config = $this->createMock(Configuration::class);

        // Pas besoin de partial mock ici si on n'appelle pas getEntityManager
        $provider = new TenantEntityManagerProvider($connProvider, $config);
        $provider->switchTenant('new_db', 'new_code');
    }

    public function testGetEntityManagerCreatesNewInstanceAfterDbChange(): void
    {
        $config = $this->createMock(Configuration::class);
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(true);

        // Séquence : 2 bases de données différentes
        $connection->expects($this->exactly(2))
            ->method('getDatabase')
            ->willReturnOnConsecutiveCalls('db_1', 'db_2');

        $connProvider = $this->createMock(TenantConnectionProvider::class);
        $connProvider->method('getConnection')->willReturn($connection);

        // Deux EMs différents simulés
        $mockEm1 = $this->createMock(EntityManagerInterface::class);
        $mockEm1->method('isOpen')->willReturn(true);
        $mockEm2 = $this->createMock(EntityManagerInterface::class);

        $provider = $this->createPartialMockProvider($connProvider, $config);

        // On s'attend à DEUX créations
        $provider->expects($this->exactly(2))
            ->method('createEntityManager')
            ->willReturnOnConsecutiveCalls($mockEm1, $mockEm2);

        // 1. Premier EM
        $em1 = $provider->getEntityManager();

        // 2. Deuxième EM (car DB a changé)
        $em2 = $provider->getEntityManager();

        $this->assertSame($mockEm1, $em1);
        $this->assertSame($mockEm2, $em2);
    }
}
