<?php

namespace App\Tests\Unit\Controller\SocialNetworkController;

use App\Controller\SocialNetworkController\SocialNetworkController;
use App\Dto\SocialNetworkDto;
use App\UseCase\SocialNetworkUseCase\GetSocialNetworksUseCase;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;

class SocialNetworkControllerTest extends TestCase
{
    private $getSocialNetworksUseCase;
    private $controller;
    private $container;
    private $serializer;

    protected function setUp(): void
    {
        $this->getSocialNetworksUseCase = $this->createMock(GetSocialNetworksUseCase::class);
        $this->controller = new SocialNetworkController($this->getSocialNetworksUseCase);

        $this->container = $this->createMock(ContainerInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);

        $this->controller->setContainer($this->container);

        $this->container->method('has')->with('serializer')->willReturn(true);
        $this->container->method('get')->with('serializer')->willReturn($this->serializer);

        $this->serializer->method('serialize')->willReturn('[]');
    }

    public function testList(): void
    {
        $dto = new SocialNetworkDto();
        $dto->name = 'facebook';
        $dto->url = 'https://facebook.com/test';

        $this->getSocialNetworksUseCase->expects($this->once())
            ->method('execute')
            ->willReturn([$dto]);

        $response = $this->controller->list();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }
}
