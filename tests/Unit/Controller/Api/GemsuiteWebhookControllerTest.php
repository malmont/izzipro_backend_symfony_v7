<?php

namespace App\Tests\Unit\Controller\Api;

use App\Controller\Api\GemsuiteWebhookController;
use App\Services\GemsuiteImporterService\GemsuiteCompanySyncHandler;
use App\Services\GemsuiteImporterService\GemsuiteSyncHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;

class GemsuiteWebhookControllerTest extends TestCase
{
    private $syncHandler;
    private $companySyncHandler;
    private $logger;
    private $controller;
    private $container;
    private $serializer;

    protected function setUp(): void
    {
        $this->syncHandler = $this->createMock(GemsuiteSyncHandler::class);
        $this->companySyncHandler = $this->createMock(GemsuiteCompanySyncHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new GemsuiteWebhookController(
            $this->syncHandler,
            $this->companySyncHandler,
            $this->logger,
            $this->createMock(\App\Services\GemsuiteImporterService\GemsuiteRentalWebhookService::class)
        );

        $this->container = $this->createMock(ContainerInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);

        $this->controller->setContainer($this->container);

        // Setup container for abstract controller json() helper
        $this->container->method('has')->with('serializer')->willReturn(true);
        $this->container->method('get')->with('serializer')->willReturn($this->serializer);

        // Default serializer behavior
        $this->serializer->method('serialize')->willReturn('{"result": "mock"}');
    }

    public function testValidationRequest(): void
    {
        $tenant_code = 'test_tenant';

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('validation'));

        $request = new \Symfony\Component\HttpFoundation\Request();
        $response = $this->controller->handleWebhook($request, $tenant_code);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testHandleProductUpdate(): void
    {
        $tenant_code = 'test_tenant';
        $endpoint = 'products';
        $id = 123;

        $this->syncHandler->expects($this->once())
            ->method('handleProductUpdate')
            ->with($tenant_code, $id);

        $request = new \Symfony\Component\HttpFoundation\Request();
        $response = $this->controller->handleWebhook($request, $tenant_code, $endpoint, $id);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testHandleCategoryUpdate(): void
    {
        $tenant_code = 'test_tenant';
        $endpoint = 'categories';
        $id = 456;

        $this->syncHandler->expects($this->once())
            ->method('handleCategoryUpdate')
            ->with($tenant_code, $id);

        $request = new \Symfony\Component\HttpFoundation\Request();
        $response = $this->controller->handleWebhook($request, $tenant_code, $endpoint, $id);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testHandleCompanyUpdate(): void
    {
        $tenant_code = 'test_tenant';
        $endpoint = 'company';
        $id = 0; // Not used for company

        $this->companySyncHandler->expects($this->once())
            ->method('handleCompanyUpdate')
            ->with($tenant_code);

        $request = new \Symfony\Component\HttpFoundation\Request();
        $response = $this->controller->handleWebhook($request, $tenant_code, $endpoint, $id);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testHandleClientUpdate(): void
    {
        $tenant_code = 'test_tenant';
        $endpoint = 'clients';
        $id = 789;

        $this->syncHandler->expects($this->once())
            ->method('handleClientUpdate')
            ->with($tenant_code, $id);

        $request = new \Symfony\Component\HttpFoundation\Request();
        $response = $this->controller->handleWebhook($request, $tenant_code, $endpoint, $id);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testUnknownEndpoint(): void
    {
        $tenant_code = 'test_tenant';
        $endpoint = 'unknown_thing';
        $id = 1;

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('non géré'));

        $request = new \Symfony\Component\HttpFoundation\Request();
        $response = $this->controller->handleWebhook($request, $tenant_code, $endpoint, $id);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testExceptionHandling(): void
    {
        $tenant_code = 'test_tenant';
        $endpoint = 'products';
        $id = 123;

        $this->syncHandler->expects($this->once())
            ->method('handleProductUpdate')
            ->willThrowException(new \RuntimeException('Sync failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Erreur lors du traitement'));

        $request = new \Symfony\Component\HttpFoundation\Request();
        $response = $this->controller->handleWebhook($request, $tenant_code, $endpoint, $id);

        $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }
}
