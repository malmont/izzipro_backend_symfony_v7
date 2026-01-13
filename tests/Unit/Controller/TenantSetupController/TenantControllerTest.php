<?php

namespace App\Tests\Unit\Controller\TenantSetupController;

use App\Controller\TenantSetupController\TenantController;
use App\Services\TenantConnectionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class TenantControllerTest extends TestCase
{
    private $tenantConnectionManager;
    private $pdoMaster;
    private $controller;
    private $frontendBaseDomain = 'myshop.com';
    private $backendBaseDomain = 'admin.myshop.com';

    protected function setUp(): void
    {
        $this->tenantConnectionManager = $this->createMock(TenantConnectionManager::class);
        $this->pdoMaster = $this->createMock(\PDO::class);

        $this->controller = new TenantController(
            $this->frontendBaseDomain,
            $this->backendBaseDomain
        );
    }

    public function testCheckReturnsTenantInfoWhenCustomDomainExists(): void
    {
        // Arrange
        $request = new Request([], [], [], [], [], ['HTTP_X-Tenant-Host' => 'custom.example.com']);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['code' => 'test_shop', 'name' => 'Test Shop']);

        $this->tenantConnectionManager->method('getPdoMaster')->willReturn($this->pdoMaster);
        $this->pdoMaster->method('prepare')->willReturn($stmt);

        // Act
        $response = $this->controller->check($request, $this->tenantConnectionManager);

        // Assert
        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['exists']);
        $this->assertEquals('found', $data['status']);
        $this->assertEquals('login', $data['action']);
        $this->assertEquals('test_shop', $data['tenant']['code']);
    }

    public function testCheckReturnsTenantInfoWhenSubdomainExists(): void
    {
        // Arrange
        $request = new Request([], [], [], [], [], ['HTTP_X-Tenant-Host' => 'shop1.myshop.com']);

        // First query (custom domain) fails
        $stmt1 = $this->createMock(\PDOStatement::class);
        $stmt1->method('fetch')->willReturn(false);

        // Second query (subdomain) succeeds
        $stmt2 = $this->createMock(\PDOStatement::class);
        $stmt2->method('fetch')->willReturn(['code' => 'shop1', 'name' => 'Shop One']);

        $this->tenantConnectionManager->method('getPdoMaster')->willReturn($this->pdoMaster);
        $this->pdoMaster->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($stmt1, $stmt2);

        // Act
        $response = $this->controller->check($request, $this->tenantConnectionManager);

        // Assert
        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['exists']);
        $this->assertEquals('found', $data['status']);
        $this->assertEquals('shop1', $data['tenant']['code']);
    }

    public function testCheckReturnsRedirectWhenPlatformSubdomainNotFound(): void
    {
        // Arrange
        $request = new Request([], [], [], [], [], ['HTTP_X-Tenant-Host' => 'new-shop.myshop.com']);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false); // Not found in DB

        $this->tenantConnectionManager->method('getPdoMaster')->willReturn($this->pdoMaster);
        $this->pdoMaster->method('prepare')->willReturn($stmt);

        // Act
        $response = $this->controller->check($request, $this->tenantConnectionManager);

        // Assert
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['exists']);
        $this->assertEquals('not_found', $data['status']);
        $this->assertEquals('redirect_create', $data['action']);

        // Check constructed setup URL
        $expectedUrl = 'https://new-shop.admin.myshop.com/setup/new-store';
        $this->assertEquals($expectedUrl, $data['setup_url']);
    }

    public function testCheckReturnsErrorWhenUnknownDomain(): void
    {
        // Arrange
        $request = new Request([], [], [], [], [], ['HTTP_X-Tenant-Host' => 'evil.com']);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false); // Not found in DB

        $this->tenantConnectionManager->method('getPdoMaster')->willReturn($this->pdoMaster);
        $this->pdoMaster->method('prepare')->willReturn($stmt);

        // Act
        $response = $this->controller->check($request, $this->tenantConnectionManager);

        // Assert
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['exists']);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('Domaine non reconnu.', $data['message']);
    }

    public function testCheckReturnsErrorOnDatabaseFailure(): void
    {
        // Arrange
        $request = new Request([], [], [], [], [], ['HTTP_X-Tenant-Host' => 'any.com']);

        $this->tenantConnectionManager->method('getPdoMaster')->willThrowException(new \Exception('Connection failed'));

        // Act
        $response = $this->controller->check($request, $this->tenantConnectionManager);

        // Assert
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(500, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('Erreur critique connexion Master.', $data['message']);
    }
}
