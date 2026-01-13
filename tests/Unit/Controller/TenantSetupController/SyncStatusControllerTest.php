<?php

namespace App\Tests\Unit\Controller\TenantSetupController;

use PHPUnit\Framework\TestCase;
use App\Controller\TenantSetupController\SyncStatusController;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Entity\SyncJob;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Twig\Environment;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Doctrine\ORM\EntityRepository;

class SyncStatusControllerTest extends TestCase
{
    private $connectionManager;
    private $emProvider;
    private $controller;
    private $container;
    private $twig;
    private $pdo;
    private $stmt;

    protected function setUp(): void
    {
        $this->connectionManager = $this->createMock(TenantConnectionManager::class);
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->twig = $this->createMock(Environment::class);
        $this->pdo = $this->createMock(\PDO::class);
        $this->stmt = $this->createMock(\PDOStatement::class);

        $this->controller = new SyncStatusController();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->container->method('has')->willReturnCallback(function ($id) {
            return $id === 'twig';
        });
        $this->container->method('get')->with('twig')->willReturn($this->twig);

        $this->controller->setContainer($this->container);
    }

    public function testInvokeTenantNotFound(): void
    {
        $this->connectionManager->method('getPdoMaster')->willReturn($this->pdo);
        $this->pdo->method('prepare')->willReturn($this->stmt);
        $this->stmt->expects($this->once())->method('execute')->with(['code' => 'invalid_tenant']);
        $this->stmt->method('fetchColumn')->willReturn(false);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        $this->controller->__invoke('invalid_tenant', 1, 'url', $this->connectionManager, $this->emProvider);
    }

    public function testInvokeTenantFoundSyncJobFound(): void
    {
        $this->connectionManager->method('getPdoMaster')->willReturn($this->pdo);
        $this->pdo->method('prepare')->willReturn($this->stmt);
        $this->stmt->method('fetchColumn')->willReturn('tenant_db');

        $tenantEm = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(EntityRepository::class);
        $syncJob = $this->createMock(SyncJob::class);

        $this->emProvider->expects($this->once())->method('switchTenant')->with('tenant_db', 'tenant_code');
        $this->emProvider->method('getEntityManager')->willReturn($tenantEm);

        $tenantEm->method('getRepository')->with(SyncJob::class)->willReturn($repository);
        $repository->method('find')->with(1)->willReturn($syncJob);

        $syncJob->method('getStatus')->willReturn('running');
        $syncJob->method('getCurrentStep')->willReturn('step 1');
        $syncJob->method('getPercent')->willReturn(50.0);
        $syncJob->method('getLastError')->willReturn(null);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('sync_status/index.html.twig', [
                'status' => 'running',
                'step' => 'step 1',
                'percent' => 50,
                'error' => null,
                'finalUrl' => base64_decode('url'),
                'tenantCode' => 'tenant_code'
            ])
            ->willReturn('rendered_template');

        $response = $this->controller->__invoke('tenant_code', 1, 'url', $this->connectionManager, $this->emProvider);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('rendered_template', $response->getContent());
    }

    public function testInvokeTenantFoundSyncJobNotFound(): void
    {
        $this->connectionManager->method('getPdoMaster')->willReturn($this->pdo);
        $this->pdo->method('prepare')->willReturn($this->stmt);
        $this->stmt->method('fetchColumn')->willReturn('tenant_db');

        $tenantEm = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(EntityRepository::class);

        $this->emProvider->method('getEntityManager')->willReturn($tenantEm);
        $tenantEm->method('getRepository')->willReturn($repository);
        $repository->method('find')->willReturn(null);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('sync_status/index.html.twig', [
                'status' => 'pending',
                'step' => 'Démarrage des services...',
                'percent' => 0,
                'error' => null,
                'finalUrl' => base64_decode('url'),
                'tenantCode' => 'tenant_code'
            ])
            ->willReturn('waiting_template');

        $response = $this->controller->__invoke('tenant_code', 1, 'url', $this->connectionManager, $this->emProvider);

        $this->assertEquals('waiting_template', $response->getContent());
    }

    public function testInvokeDatabaseConnectionError(): void
    {
        $this->connectionManager->method('getPdoMaster')->willReturn($this->pdo);
        $this->pdo->method('prepare')->willReturn($this->stmt);
        $this->stmt->method('fetchColumn')->willReturn('tenant_db');

        $this->emProvider->method('switchTenant')->willThrowException(new \Exception('Connection failed'));

        $this->twig->expects($this->once())
            ->method('render')
            ->with('sync_status/index.html.twig', $this->callback(function ($context) {
                return $context['status'] === 'pending';
            }))
            ->willReturn('waiting_template');

        $response = $this->controller->__invoke('tenant_code', 1, 'url', $this->connectionManager, $this->emProvider);

        $this->assertEquals('waiting_template', $response->getContent());
    }
}
