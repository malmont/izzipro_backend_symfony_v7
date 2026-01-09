<?php

namespace App\Tests\Unit\Services\GemsuiteImporterService;

use App\Services\GemsuiteImporterService\GemsuiteImporter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GemsuiteImporterTest extends TestCase
{
    private $client;
    private $logger;
    private $gemsuiteApiUrl = 'https://api.example.com/';
    private $importer;

    protected function setUp(): void
    {
        $this->client = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->importer = new GemsuiteImporter(
            $this->client,
            $this->logger,
            $this->gemsuiteApiUrl
        );
    }

    public function testCheckPrerequisitesSuccess(): void
    {
        $token = 'valid_token';

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn(['data' => ['Category 1']]);

        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', $this->gemsuiteApiUrl . 'categories', ['auth_bearer' => $token])
            ->willReturn($response);

        $this->logger->expects($this->exactly(2)) // Start info + Success info
            ->method('info');

        $this->importer->checkPrerequisites($token);
    }

    public function testCheckPrerequisitesThrowsExceptionIfNoData(): void
    {
        $token = 'valid_token';

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn(['data' => []]);

        $this->client->method('request')->willReturn($response);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Pré-vérification échouée'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Aucune catégorie trouvée sur GEM-SUITE');

        $this->importer->checkPrerequisites($token);
    }
}
