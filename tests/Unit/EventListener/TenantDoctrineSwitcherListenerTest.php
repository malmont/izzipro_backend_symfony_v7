<?php

namespace App\Tests\Unit\EventListener;

use App\Dto\TenantConfig;
use App\EventListener\TenantDoctrineSwitcherListener;
use App\Services\TenantConnectionManager;
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
    private $tenantManager;
    private $logger;
    private $listener;

    private const BACKEND_DOMAIN = 'backend.com';

    protected function setUp(): void
    {
        $this->tenantConnectionProvider = $this->createMock(TenantConnectionProvider::class);
        $this->tenantManager = $this->createMock(TenantConnectionManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new TenantDoctrineSwitcherListener(
            $this->tenantConnectionProvider,
            $this->tenantManager,
            $this->logger,
            self::BACKEND_DOMAIN
        );
    }

    private function createEvent(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, $type);
    }

    public function testOnKernelRequestExcludesSubRequests(): void
    {
        $request = new Request();
        $event = $this->createEvent($request, HttpKernelInterface::SUB_REQUEST);

        $this->tenantManager->expects($this->never())->method('findTenantConfigByHost');
        $this->listener->onKernelRequest($event);
    }

    public function testOnKernelRequestSkipsExcludedPaths(): void
    {
        $request = Request::create('/api/tenant/check');
        $event = $this->createEvent($request);

        $this->tenantManager->expects($this->never())->method('findTenantConfigByHost');
        $this->listener->onKernelRequest($event);
    }

    public function testOnKernelRequestSwitchWhenConfigFound(): void
    {
        $request = Request::create('/');
        $request->headers->set('HOST', 'tenant.example.com');
        $event = $this->createEvent($request);

        $tenantConfig = new TenantConfig();
        $tenantConfig->setCode('tenant1')->setDbname('db_tenant1');

        $this->tenantManager->expects($this->once())
            ->method('findTenantConfigByHost')
            ->with('tenant.example.com')
            ->willReturn($tenantConfig);

        // Mock current connection state
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['dbname' => 'db_master']);
        $this->tenantConnectionProvider->method('getConnection')->willReturn($connection);
        $this->tenantConnectionProvider->method('getTenantCode')->willReturn(null);

        $this->tenantConnectionProvider->expects($this->once())
            ->method('switchTenant')
            ->with('db_tenant1', 'tenant1');

        $this->listener->onKernelRequest($event);
    }

    public function testOnKernelRequestSwitchWhenUsingCustomHeader(): void
    {
        $request = Request::create('/');
        $request->headers->set('X-Tenant-Host', 'custom.com');
        $event = $this->createEvent($request);

        $tenantConfig = new TenantConfig();
        $tenantConfig->setCode('custom_tenant')->setDbname('db_custom');

        $this->tenantManager->expects($this->once())
            ->method('findTenantConfigByHost')
            ->with('custom.com')
            ->willReturn($tenantConfig);

        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['dbname' => 'db_master']);
        $this->tenantConnectionProvider->method('getConnection')->willReturn($connection);
        $this->tenantConnectionProvider->method('getTenantCode')->willReturn(null);

        $this->tenantConnectionProvider->expects($this->once())
            ->method('switchTenant')
            ->with('db_custom', 'custom_tenant');

        $this->listener->onKernelRequest($event);
    }

    public function testOnKernelRequestBackendDefaultTenantFallback(): void
    {
        $request = Request::create('/');
        $request->headers->set('HOST', self::BACKEND_DOMAIN);
        $event = $this->createEvent($request);

        // First call returns null (no specific config for backend domain)
        $this->tenantManager->expects($this->exactly(2))
            ->method('findTenantConfigByHost')
            ->withConsecutive([self::BACKEND_DOMAIN], ['tenantdefaut'])
            ->willReturnOnConsecutiveCalls(null, (new TenantConfig())->setCode('tenantdefaut')->setDbname('db_default'));

        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['dbname' => 'db_master']);
        $this->tenantConnectionProvider->method('getConnection')->willReturn($connection);
        $this->tenantConnectionProvider->method('getTenantCode')->willReturn(null);

        $this->tenantConnectionProvider->expects($this->once())
            ->method('switchTenant')
            ->with('db_default', 'tenantdefaut');

        $this->listener->onKernelRequest($event);
    }

    public function testOnKernelRequestNoSwitchIfAlreadyConnected(): void
    {
        $request = Request::create('/');
        $request->headers->set('HOST', 'tenant1.example.com');
        $event = $this->createEvent($request);

        $tenantConfig = (new TenantConfig())->setCode('tenant1')->setDbname('db_tenant1');

        $this->tenantManager->method('findTenantConfigByHost')->willReturn($tenantConfig);

        // Mock current connection already matching
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['dbname' => 'db_tenant1']);
        $this->tenantConnectionProvider->method('getConnection')->willReturn($connection);
        $this->tenantConnectionProvider->method('getTenantCode')->willReturn('tenant1');

        $this->tenantConnectionProvider->expects($this->never())->method('switchTenant');

        $this->listener->onKernelRequest($event);
    }

    public function testOnKernelRequestNoValuesFound(): void
    {
        $request = Request::create('/');
        $request->headers->set('HOST', 'unknown.example.com');
        $event = $this->createEvent($request);

        $this->tenantManager->method('findTenantConfigByHost')->willReturn(null);
        $this->tenantConnectionProvider->expects($this->never())->method('switchTenant');

        $this->listener->onKernelRequest($event);
    }
}
