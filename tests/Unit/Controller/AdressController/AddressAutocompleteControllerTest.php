<?php

namespace App\Tests\Unit\Controller\AdressController;

use App\Controller\AdressController\AddressAutocompleteController;
use App\Dto\AddressDetailsInputDto;
use App\Dto\AddressDetailsResultDto;
use App\Dto\AddressSuggestionInputDto;
use App\UseCase\AdressUseCase\GetAddressDetailsUseCase;
use App\UseCase\AdressUseCase\SuggestAddressUseCase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class AddressAutocompleteControllerTest extends TestCase
{
    private $suggestUseCase;
    private $detailsUseCase;
    private $controller;
    private $container;

    protected function setUp(): void
    {
        $this->suggestUseCase = $this->createMock(SuggestAddressUseCase::class);
        $this->detailsUseCase = $this->createMock(GetAddressDetailsUseCase::class);

        $this->controller = new AddressAutocompleteController(
            $this->suggestUseCase,
            $this->detailsUseCase
        );

        $this->container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $this->controller->setContainer($this->container);

        $this->container->method('has')->willReturn(false);
    }

    public function testSuggestSuccess(): void
    {
        // AddressSuggestionInputDto expects 'q' parameter which maps to 'query' property
        $request = new Request(['q' => 'Paris']);
        $expectedResult = ['predictions' => [['description' => 'Paris, France']]];

        $this->suggestUseCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(function (AddressSuggestionInputDto $dto) {
                return $dto->query === 'Paris';
            }))
            ->willReturn($expectedResult);

        $response = $this->controller->suggest($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals($expectedResult, $content);
    }

    public function testDetailsSuccess(): void
    {
        // AddressDetailsInputDto expects 'place_id' parameter which maps to 'placeId' property
        // GetAddressDetailsUseCase returns AddressDetailsResultDto, not array
        $request = new Request(['place_id' => '12345']);

        $resultData = [
            'street1' => '1 Champs Elysees',
            'street2' => null,
            'city' => 'Paris',
            'province' => 'Ile de France',
            'postal_code' => '75008',
            'country' => 'FR'
        ];
        $resultDto = new AddressDetailsResultDto($resultData);

        $this->detailsUseCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(function (AddressDetailsInputDto $dto) {
                return $dto->placeId === '12345';
            }))
            ->willReturn($resultDto);

        $response = $this->controller->details($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);

        $this->assertEquals('1 Champs Elysees', $content['street1']);
        $this->assertEquals('Paris', $content['city']);
        $this->assertEquals('FR', $content['country']);
    }
}
