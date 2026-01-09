<?php

namespace App\Tests\Unit\Services\GemsuiteImporterService;

use App\Entity\Adress;
use App\Entity\GemsuiteClient;
use App\Entity\User;
use App\Services\GemsuiteImporterService\GemsuiteClientUpdater;
use App\Services\TenantConnectionManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GemsuiteClientUpdaterTest extends TestCase
{
    private $client;
    private $tenantManager;
    private $logger;
    private $gemsuiteApiUrl = 'https://api.example.com/';
    private $updater;

    protected function setUp(): void
    {
        $this->client = $this->createMock(HttpClientInterface::class);
        $this->tenantManager = $this->createMock(TenantConnectionManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->updater = new GemsuiteClientUpdater(
            $this->client,
            $this->tenantManager,
            $this->logger,
            $this->gemsuiteApiUrl
        );
    }

    public function testSyncAddressDoesNothingIfNoGemsuiteClient(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getGemsuiteClient')->willReturn(null);
        $user->method('getEmail')->willReturn('test@example.com');

        $address = $this->createMock(Adress::class);

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('n\'a pas de client GEM-SUITE associé'));

        $this->updater->syncAddress($user, $address);
    }

    public function testSyncAddressDoesNothingIfNoToken(): void
    {
        $user = $this->createMock(User::class);
        $gemsuiteClient = $this->createMock(GemsuiteClient::class);
        $gemsuiteClient->method('getGemsuiteId')->willReturn(123);
        $user->method('getGemsuiteClient')->willReturn($gemsuiteClient);

        $address = $this->createMock(Adress::class);

        $this->tenantManager->method('getCurrentTenantCode')->willReturn('TENANT');
        $this->tenantManager->method('getTenantToken')->willReturn(null);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Aucun token pour le tenant'));

        $this->updater->syncAddress($user, $address);
    }

    public function testSyncAddressSuccess(): void
    {
        $user = $this->createMock(User::class);
        $gemsuiteClient = $this->createMock(GemsuiteClient::class);
        $gemsuiteClient->method('getGemsuiteId')->willReturn(123);
        $user->method('getGemsuiteClient')->willReturn($gemsuiteClient);

        $address = $this->createMock(Adress::class);
        $address->method('getAddress')->willReturn('123 Main St');
        $address->method('getComplement')->willReturn('Apt 4');
        $address->method('getCity')->willReturn('City');
        $address->method('getProvince')->willReturn('State');
        $address->method('getCodepostal')->willReturn('12345');
        $address->method('getPhone')->willReturn('555-1234');
        $address->method('getCountry')->willReturn('Country');

        $this->tenantManager->method('getCurrentTenantCode')->willReturn('TENANT');
        $this->tenantManager->method('getTenantToken')->willReturn('valid_token');

        $this->client->expects($this->once())
            ->method('request')
            ->with('PUT', $this->gemsuiteApiUrl . 'clients/123', $this->callback(function ($options) {
                return $options['auth_bearer'] === 'valid_token'
                    && $options['json']['address'] === '123 Main St';
            }));

        $this->logger->expects($this->exactly(2)) // Info Start + Info Success
            ->method('info');

        $this->updater->syncAddress($user, $address);
    }

    public function testSyncAddressHandlesException(): void
    {
        $user = $this->createMock(User::class);
        $gemsuiteClient = $this->createMock(GemsuiteClient::class);
        $gemsuiteClient->method('getGemsuiteId')->willReturn(123);
        $user->method('getGemsuiteClient')->willReturn($gemsuiteClient);

        $address = $this->createMock(Adress::class);

        $this->tenantManager->method('getCurrentTenantCode')->willReturn('TENANT');
        $this->tenantManager->method('getTenantToken')->willReturn('valid_token');

        $this->client->method('request')->willThrowException(new \Exception('API Error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Erreur lors de la synchronisation'));

        $this->updater->syncAddress($user, $address);
    }
}
