<?php

namespace App\Tests\Unit\Service;

use App\Services\AdressService\AddressAutocompleteService;
use App\Services\AdressService\GooglePlacesKeyProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class AddressAutocompleteServiceTest extends TestCase
{
    public function testSuggestThrowsExceptionIfNoApiKey(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $keyProvider = $this->createMock(GooglePlacesKeyProvider::class);
        $keyProvider->method('getKey')->willReturn('');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('La clé API Google Places n\'est pas configurée');

        $service = new AddressAutocompleteService($client, $keyProvider, $logger);
        $service->suggest('Paris');
    }

    public function testSuggestReturnsPredictionsOnSuccess(): void
    {
        // 1. Simulation de la réponse de Google
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('toArray')->willReturn([
            'predictions' => [
                ['description' => 'Paris, France', 'place_id' => 'ChIJ...'],
                ['description' => 'Paris, TX, USA', 'place_id' => 'AbCd...'],
            ]
        ]);

        // 2. Configuration du Client HTTP
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects($this->once())
            ->method('request')
            ->with('GET', 'https://maps.googleapis.com/maps/api/place/autocomplete/json')
            ->willReturn($mockResponse);

        $keyProvider = $this->createMock(GooglePlacesKeyProvider::class);
        $keyProvider->method('getKey')->willReturn('FAKE_KEY');

        $logger = $this->createMock(LoggerInterface::class);

        // 3. Test
        $service = new AddressAutocompleteService($client, $keyProvider, $logger);
        $results = $service->suggest('Paris');

        $this->assertCount(2, $results);
        $this->assertEquals('Paris, France', $results[0]['description']);
        $this->assertEquals('ChIJ...', $results[0]['place_id']);
    }

    public function testGetDetailsMapsGoogleDataToCleanAddress(): void
    {

        $googleJson = [
            'result' => [
                'address_components' => [
                    ['types' => ['street_number'], 'long_name' => '123'],
                    ['types' => ['route'], 'long_name' => 'Champs-Élysées'],
                    ['types' => ['locality'], 'long_name' => 'Paris'],
                    ['types' => ['administrative_area_level_1'], 'short_name' => 'IDF'], // Province
                    ['types' => ['postal_code'], 'long_name' => '75008'],
                    ['types' => ['country'], 'short_name' => 'FR'],
                ]
            ]
        ];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('toArray')->willReturn($googleJson);

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($mockResponse);

        $keyProvider = $this->createMock(GooglePlacesKeyProvider::class);
        $keyProvider->method('getKey')->willReturn('FAKE_KEY');

        $service = new AddressAutocompleteService($client, $keyProvider, $this->createMock(LoggerInterface::class));

        // 2. Action
        $address = $service->getDetails('some_place_id');

        // 3. Assertions : On vérifie que ta logique de concaténation fonctionne
        $this->assertEquals('123 Champs-Élysées', $address['street1']);
        $this->assertEquals('Paris', $address['city']);
        $this->assertEquals('IDF', $address['province']);
        $this->assertEquals('FR', $address['country']);
        $this->assertEquals('75008', $address['postal_code']);
    }

    public function testApiErrorLogsAndThrowsException(): void
    {
        // 1. Simuler une erreur Google (ex: Clé invalide côté Google)
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(400);
        $mockResponse->method('toArray')->willReturn(['error_message' => 'The provided API key is invalid.']);

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($mockResponse);

        $keyProvider = $this->createMock(GooglePlacesKeyProvider::class);
        $keyProvider->method('getKey')->willReturn('BAD_KEY');

        // 2. On vérifie que le Logger est appelé !
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $service = new AddressAutocompleteService($client, $keyProvider, $logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API Google Places: The provided API key is invalid.');

        $service->suggest('Fail');
    }

    public function testGetDetailsThrowsExceptionIfNoApiKey(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $keyProvider = $this->createMock(GooglePlacesKeyProvider::class);
        $keyProvider->method('getKey')->willReturn('');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('La clé API Google Places n\'est pas configurée');

        $service = new AddressAutocompleteService($client, $keyProvider, $logger);
        $service->getDetails('some_id');
    }

    public function testGetDetailsApiErrorLogsAndThrowsException(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(500);
        $mockResponse->method('toArray')->willReturn(['error_message' => 'Internal Server Error']);

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($mockResponse);

        $keyProvider = $this->createMock(GooglePlacesKeyProvider::class);
        $keyProvider->method('getKey')->willReturn('KEY');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $service = new AddressAutocompleteService($client, $keyProvider, $logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API Google Places: Internal Server Error');

        $service->getDetails('some_id');
    }

    public function testSuggestReturnsEmptyArrayIfNoPredictions(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        // Simulation réponse valide mais sans predictions ou predictions vide
        $mockResponse->method('toArray')->willReturn(['status' => 'ZERO_RESULTS']);

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($mockResponse);

        $keyProvider = $this->createMock(GooglePlacesKeyProvider::class);
        $keyProvider->method('getKey')->willReturn('KEY');

        $service = new AddressAutocompleteService($client, $keyProvider, $this->createMock(LoggerInterface::class));

        $this->assertEmpty($service->suggest('Nowhere'));
    }
}
