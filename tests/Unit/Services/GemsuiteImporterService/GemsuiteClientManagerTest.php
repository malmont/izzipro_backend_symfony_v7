<?php

namespace App\Tests\Services\GemsuiteImporterService;

use App\Entity\GemsuiteClient;
use App\Services\GemsuiteImporterService\GemsuiteClientManager;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GemsuiteClientManagerTest extends TestCase
{
    private $client;
    private $tenantManager;
    private $emProvider;
    private $logger;
    private $gemsuiteApiUrl = 'https://api.example.com/';
    private $manager;

    protected function setUp(): void
    {
        $this->client = $this->createMock(HttpClientInterface::class);
        $this->tenantManager = $this->createMock(TenantConnectionManager::class);
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->manager = new GemsuiteClientManager(
            $this->client,
            $this->tenantManager,
            $this->emProvider,
            $this->logger,
            $this->gemsuiteApiUrl
        );
    }

    public function testFindOrCreateClientReturnsLocalClientIfFound(): void
    {
        $email = 'test@example.com';
        $firstName = 'John';
        $lastName = 'Doe';
        $tenantCode = 'TENANT1';

        $localClient = new GemsuiteClient();
        $localClient->setEmail($email);
        $localClient->setGemsuiteId(123);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => $email])
            ->willReturn($localClient);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('getRepository')
            ->with(GemsuiteClient::class)
            ->willReturn($repository);

        $this->emProvider->expects($this->once())
            ->method('switchTenant')
            ->with('db_' . $tenantCode, $tenantCode);

        $this->emProvider->expects($this->once())
            ->method('getEntityManager')
            ->willReturn($entityManager);

        $result = $this->manager->findOrCreateClient($email, $firstName, $lastName, $tenantCode);

        $this->assertSame($localClient, $result);
    }

    public function testFindOrCreateClientCreatesClientOnApiIfNotFoundLocally(): void
    {
        $email = 'new@example.com';
        $firstName = 'Jane';
        $lastName = 'Doe';
        $tenantCode = 'TENANT2';
        $token = 'fake_token';

        // Mock getting EntityManager
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => $email])
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('getRepository')
            ->with(GemsuiteClient::class)
            ->willReturn($repository);

        // Expect persist and flush for new client
        $entityManager->expects($this->once())->method('persist')->with($this->isInstanceOf(GemsuiteClient::class));
        $entityManager->expects($this->once())->method('flush');

        $this->emProvider->expects($this->once())
            ->method('getEntityManager')
            ->willReturn($entityManager);

        // Mock getting token
        $this->tenantManager->expects($this->once())
            ->method('getTenantToken')
            ->with($tenantCode)
            ->willReturn($token);

        // Mock API call
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->any()) // Called multiple times for status and content
            ->method('getStatusCode')
            ->willReturn(201);

        $response->expects($this->once())
            ->method('toArray')
            ->willReturn([
                'data' => [
                    'id' => 999,
                    'email' => $email,
                    'name' => 'Jane Doe'
                ]
            ]);

        $this->client->expects($this->once())
            ->method('request')
            ->with('POST', $this->gemsuiteApiUrl . 'clients', $this->callback(function ($options) use ($token, $email) {
                return $options['auth_bearer'] === $token && $options['json']['email'] === $email;
            }))
            ->willReturn($response);

        $result = $this->manager->findOrCreateClient($email, $firstName, $lastName, $tenantCode);

        $this->assertInstanceOf(GemsuiteClient::class, $result);
        $this->assertEquals(999, $result->getGemsuiteId());
        $this->assertEquals($email, $result->getEmail());
    }

    public function testFindOrCreateClientReturnsNullIfNoToken(): void
    {
        $email = 'notoken@example.com';
        $tenantCode = 'NOTOKEN';

        // Mock EM
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $this->emProvider->method('getEntityManager')->willReturn($entityManager);

        // Mock No Token
        $this->tenantManager->expects($this->once())
            ->method('getTenantToken')
            ->with($tenantCode)
            ->willReturn(null);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(sprintf('Aucun token pour le tenant "%s".', $tenantCode));

        $result = $this->manager->findOrCreateClient($email, 'F', 'L', $tenantCode);

        $this->assertNull($result);
    }

    public function testFindOrCreateClientReturnsNullIfApiFail(): void
    {
        $email = 'fail@example.com';
        $tenantCode = 'FAIL';
        $token = 'valid_token';

        // Mock EM
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $this->emProvider->method('getEntityManager')->willReturn($entityManager);

        // Mock Token
        $this->tenantManager->method('getTenantToken')->willReturn($token);

        // Mock API Fail
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);

        $this->client->method('request')->willReturn($response);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('La création du client sur GEM-SUITE a échoué.'));

        $result = $this->manager->findOrCreateClient($email, 'F', 'L', $tenantCode);

        $this->assertNull($result);
    }

    public function testFindOrCreateClientHandlesException(): void
    {
        $email = 'ex@example.com';
        $tenantCode = 'EX';

        $this->emProvider->method('getEntityManager')->willThrowException(new \Exception('DB Error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Erreur de communication'));

        $result = $this->manager->findOrCreateClient($email, 'F', 'L', $tenantCode);

        $this->assertNull($result);
    }
}
