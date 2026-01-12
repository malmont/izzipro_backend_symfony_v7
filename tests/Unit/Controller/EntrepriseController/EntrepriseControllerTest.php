<?php

namespace App\Tests\Unit\Controller\EntrepriseController;

use App\Controller\EntrepriseController\EntrepriseController;
use App\Dto\EntrepriseDto;
use App\UseCase\EntrepriseUsecase\CreateEntrepriseUseCase;
use App\UseCase\EntrepriseUsecase\GetEntrepriseUseCase;
use App\Services\TenantCacheService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Cache\ItemInterface;

class EntrepriseControllerTest extends TestCase
{
    private $createEntrepriseUseCase;
    private $getEntrepriseUseCase;
    private $cache;
    private $controller;
    private $container;
    private $serializer;

    protected function setUp(): void
    {
        $this->createEntrepriseUseCase = $this->createMock(CreateEntrepriseUseCase::class);
        $this->getEntrepriseUseCase = $this->createMock(GetEntrepriseUseCase::class);
        $this->cache = $this->createMock(TenantCacheService::class);

        $this->controller = new EntrepriseController(
            $this->createEntrepriseUseCase,
            $this->getEntrepriseUseCase,
            $this->cache
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

    public function testCreate(): void
    {
        $payload = ['name' => 'Test Corp'];
        $request = new Request([], [], [], [], [], [], json_encode($payload));

        $this->createEntrepriseUseCase->expects($this->once())
            ->method('execute')
            ->with($payload)
            ->willReturn(new EntrepriseDto());

        $response = $this->controller->create($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testGetEntrepriseSuccess(): void
    {
        $id = 1;
        $request = new Request(['locale' => 'fr']);

        // Mock cache
        $this->cache->expects($this->once())
            ->method('get')
            ->will($this->returnCallback(function ($key, $callback, $ttl, $tags) {
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            }));

        $this->getEntrepriseUseCase->expects($this->once())
            ->method('execute')
            ->with($id, $this->anything(), 'fr')
            ->willReturn(new EntrepriseDto());

        $response = $this->controller->getEntreprise($id, $request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testGetEntrepriseNotFound(): void
    {
        $id = 999;
        $request = new Request(['locale' => 'fr']);

        $this->cache->expects($this->once())
            ->method('get')
            ->will($this->returnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            }));

        $this->getEntrepriseUseCase->expects($this->once())
            ->method('execute')
            ->willReturn(null);

        $response = $this->controller->getEntreprise($id, $request);

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }
}
