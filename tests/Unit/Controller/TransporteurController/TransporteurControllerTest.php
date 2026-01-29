<?php

namespace App\Tests\Unit\Controller\TransporteurController;

use App\Controller\TransporteurController\TransporteurController;
use App\Dto\TransporteurDTO;
use App\Entity\Transporteur;
use App\Services\TenantCacheService;
use App\UseCase\TransporteurUseCase\CreateTransporteurUseCase;
use App\UseCase\TransporteurUseCase\DeleteTransporteurUseCase;
use App\UseCase\TransporteurUseCase\GetTransporteursUseCase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\Cache\ItemInterface;

class TransporteurControllerTest extends TestCase
{
    private $getTransporteursUseCase;
    private $createTransporteurUseCase;
    private $deleteTransporteurUseCase;
    private $cache;
    private $controller;
    private $container;

    protected function setUp(): void
    {
        $this->getTransporteursUseCase = $this->createMock(GetTransporteursUseCase::class);
        $this->createTransporteurUseCase = $this->createMock(CreateTransporteurUseCase::class);
        $this->deleteTransporteurUseCase = $this->createMock(DeleteTransporteurUseCase::class);
        $this->cache = $this->createMock(TenantCacheService::class);

        $this->controller = new TransporteurController(
            $this->getTransporteursUseCase,
            $this->createTransporteurUseCase,
            $this->deleteTransporteurUseCase,
            $this->cache
        );

        $this->container = $this->createMock(ContainerInterface::class);
        $this->controller->setContainer($this->container);
    }

    public function testGetTransporteurs(): void
    {
        $transporteurData = [
            ['id' => 1, 'name' => 'DHL'],
            ['id' => 2, 'name' => 'FedEx']
        ];

        // The controller uses $this->cache->get(...)
        // We need to mock the callback execution if we want to test the use case call, 
        // or just mock the cache return if we assume cache works.
        // Assuming cache->get executes the callback if item missed, but here we can just return data.

        $this->cache->expects($this->once())
            ->method('get')
            ->with('transporteurs_all', $this->anything())
            ->willReturn($transporteurData);

        $response = $this->controller->getTransporteurs();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(JsonResponse::HTTP_OK, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertCount(2, $content);
        $this->assertEquals('DHL', $content[0]['name']);
    }

    public function testCreateTransporteur(): void
    {
        $payload = [
            'name' => 'UPS',
            'logo' => 'ups_logo.png',
            'contact' => 'contact@ups.com'
        ];
        $request = new Request([], [], [], [], [], [], json_encode($payload));

        $transporteur = $this->createMock(Transporteur::class);
        $transporteur->method('getId')->willReturn(10);

        $this->createTransporteurUseCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($dto) use ($payload) {
                return $dto instanceof TransporteurDTO
                    && $dto->name === $payload['name']
                    && $dto->logo === $payload['logo']
                    && $dto->contact === $payload['contact'];
            }))
            ->willReturn($transporteur);

        $response = $this->controller->createTransporteur($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(JsonResponse::HTTP_CREATED, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Transporteur created', $content['success']);
        $this->assertEquals(10, $content['transporteur_id']);
    }

    public function testDeleteTransporteur(): void
    {
        $transporteur = $this->createMock(Transporteur::class);

        $this->deleteTransporteurUseCase->expects($this->once())
            ->method('execute')
            ->with($transporteur);

        $response = $this->controller->deleteTransporteur($transporteur);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(JsonResponse::HTTP_NO_CONTENT, $response->getStatusCode());

        // NO_CONTENT usually has empty body, but the controller returns JsonResponse with content.
        // Symfony JsonResponse with HTTP_NO_CONTENT might strip content? 
        // Let's check the controller implementation: 
        // return new JsonResponse(['success' => 'Transporteur deleted'], JsonResponse::HTTP_NO_CONTENT);
        // Standard HTTP 204 does not allow content. Symfony might handle this.
        // If strict 204 behavior, content is empty. If loose, it might exist.
        // We will check existing behavior or just assert status.
    }
}
