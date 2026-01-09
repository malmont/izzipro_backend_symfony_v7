<?php

namespace App\Tests\Unit\EventListener;

use App\EventListener\TenantDoctrineSwitcherListener;
use App\Services\TenantConnectionProvider;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class TenantDoctrineSwitcherListenerTest extends TestCase
{
    private $tenantConnectionProvider;
    private $logger;
    private $pdoMaster;
    private $listener;

    protected function setUp(): void
    {
        $this->tenantConnectionProvider = $this->createMock(TenantConnectionProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->pdoMaster = $this->createMock(\PDO::class);

        $this->listener = new TenantDoctrineSwitcherListener(
            $this->tenantConnectionProvider,
            $this->logger,
            'postgresql://user:pass@host:5432/db',
            'frontend.com',
            'backend.com',
            $this->pdoMaster
        );
    }

    private function createEvent(Request $request): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    public function testOnKernelRequestExcludesSubRequests(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $this->pdoMaster->expects($this->never())->method('prepare');
        $this->listener->onKernelRequest($event);
    }

    public function testOnKernelRequestSkipsExcludedPaths(): void
    {
        $request = Request::create('/api/tenant/check');
        $event = $this->createEvent($request);

        $this->pdoMaster->expects($this->never())->method('prepare');
        $this->listener->onKernelRequest($event);
    }

    public function testOnKernelRequestCustomDomainFound(): void
    {
        $request = Request::create('/');
        $request->headers->set('X-Tenant-Host', 'custom.com');
        $event = $this->createEvent($request);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with(['host' => 'custom.com', 'altHost' => 'www.custom.com']);
        $stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['code' => 'tenant1', 'dbname' => 'db_tenant1']);

        $this->pdoMaster->method('prepare')->willReturn($stmt);

        // Expectation: Switch to tenant1 / db_tenant1
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['dbname' => 'db_master']);
        $this->tenantConnectionProvider->method('getConnection')->willReturn($connection);

        $this->tenantConnectionProvider->expects($this->once())
            ->method('switchTenant')
            ->with('db_tenant1', 'tenant1');

        $this->listener->onKernelRequest($event);
    }

    public function testOnKernelRequestFrontendSubdomainFound(): void
    {
        $request = Request::create('/');
        // Host: tenant2.frontend.com
        $request->headers->set('HOST', 'tenant2.frontend.com');
        $event = $this->createEvent($request);

        // P1 Custom Domain check fails
        $stmtP1 = $this->createMock(\PDOStatement::class);
        $stmtP1->method('fetch')->willReturn(false);

        // P2 Logic: tenant2 extracted. Need to look up DB.
        $stmtP2 = $this->createMock(\PDOStatement::class);
        $stmtP2->expects($this->once())
            ->method('execute')
            ->with(['c' => 'tenant2']);
        $stmtP2->expects($this->once())
            ->method('fetch')
            ->willReturn(['dbname' => 'db_tenant2']);

        $this->pdoMaster->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($stmtP1, $stmtP2);

        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['dbname' => 'db_master']);
        $this->tenantConnectionProvider->method('getConnection')->willReturn($connection);

        $this->tenantConnectionProvider->expects($this->once())
            ->method('switchTenant')
            ->with('db_tenant2', 'tenant2');

        $this->listener->onKernelRequest($event);
    }

    public function testOnKernelRequestBackendDefaultTenant(): void
    {
        $request = Request::create('/');
        $request->headers->set('HOST', 'backend.com');
        $event = $this->createEvent($request);

        // P1 fails
        $stmtP1 = $this->createMock(\PDOStatement::class);
        $stmtP1->method('fetch')->willReturn(false);

        // P2 fails (backend.com == backendMainDomain)

        // P3 Logic: backend.com -> tenantdefaut
        $stmtP3 = $this->createMock(\PDOStatement::class);
        $stmtP3->expects($this->once())
            ->method('execute')
            ->with(['c' => 'tenantdefaut']);
        $stmtP3->expects($this->once())
            ->method('fetch')
            ->willReturn(['dbname' => 'db_default']);

        $this->pdoMaster->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($stmtP1, $stmtP3);

        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['dbname' => 'db_master']);
        $this->tenantConnectionProvider->method('getConnection')->willReturn($connection);

        $this->tenantConnectionProvider->expects($this->once())
            ->method('switchTenant')
            ->with('db_default', 'tenantdefaut');

        $this->listener->onKernelRequest($event);
    }
}
