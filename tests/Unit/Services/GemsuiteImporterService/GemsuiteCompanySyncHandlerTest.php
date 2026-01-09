<?php

namespace App\Tests\Unit\Services\GemsuiteImporterService;

use App\Entity\AddressEntreprise;
use App\Entity\Entreprise;
use App\Entity\HomeSlider;
use App\Services\GemsuiteImporterService\GemsuiteCompanySyncHandler;
use App\Services\GemsuiteImporterService\GemsuiteImageUrlBuilder;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TranslationGeneratorService\TranslationGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GemsuiteCompanySyncHandlerTest extends TestCase
{
    private $client;
    private $emProvider;
    private $tenantManager;
    private $logger;
    private $imageUrlBuilder;
    private $translationGenerator;
    private $gemsuiteApiUrl = 'https://api.example.com/';
    private $handler;

    protected function setUp(): void
    {
        $this->client = $this->createMock(HttpClientInterface::class);
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->tenantManager = $this->createMock(TenantConnectionManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->imageUrlBuilder = $this->createMock(GemsuiteImageUrlBuilder::class);
        $this->translationGenerator = $this->createMock(TranslationGeneratorService::class);

        $this->handler = new GemsuiteCompanySyncHandler(
            $this->client,
            $this->emProvider,
            $this->tenantManager,
            $this->logger,
            $this->imageUrlBuilder,
            $this->translationGenerator,
            $this->gemsuiteApiUrl
        );
    }

    public function testHandleCompanyUpdateAbortsIfNoToken(): void
    {
        $tenantCode = 'NO_TOKEN';
        $this->tenantManager->expects($this->once())
            ->method('getTenantToken')
            ->with($tenantCode)
            ->willReturn(null);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Aucun token trouvé'));

        $this->handler->handleCompanyUpdate($tenantCode);
    }

    public function testHandleCompanyUpdateAbortsIfNoData(): void
    {
        $tenantCode = 'NO_DATA';
        $token = 'valid_token';

        $this->tenantManager->method('getTenantToken')->willReturn($token);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn(['data' => []]);

        $this->client->method('request')->willReturn($response);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Données de l\'entreprise non trouvées'));

        $this->handler->handleCompanyUpdate($tenantCode);
    }

    public function testHandleCompanyUpdateSuccess(): void
    {
        $tenantCode = 'SUCCESS';
        $token = 'valid_token';

        $this->tenantManager->method('getTenantToken')->willReturn($token);

        $companyData = [
            'nom' => 'My Company',
            'email' => 'info@company.com',
            'website_link' => 'https://mysite.com/shop',
            'adresse' => '123 Street',
            'ville' => 'City',
            'prov' => 'Prov',
            'cp' => '12345',
            'country' => 1,
            'tel' => '555-5555',
            'website_intro_text1' => 'Welcome',
            // ... add other needed fields
        ];

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn(['data' => [$companyData]]);
        $this->client->method('request')->willReturn($response);

        // Mock EntityManager and Repositories
        $entrepriseRepo = $this->createMock(EntityRepository::class);
        $entrepriseRepo->method('findOneBy')->willReturn(null); // Return null to simulate new entity

        $homeSliderRepo = $this->createMock(EntityRepository::class);
        $homeSliderRepo->method('findOneBy')->willReturn(null); // Return null for new entity

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')
            ->will($this->returnValueMap([
                [Entreprise::class, $entrepriseRepo],
                [HomeSlider::class, $homeSliderRepo],
            ]));

        $entityManager->expects($this->atLeast(2))->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $this->emProvider->method('getEntityManager')->willReturn($entityManager);

        $this->translationGenerator->expects($this->exactly(2))->method('generateTranslations');

        $this->handler->handleCompanyUpdate($tenantCode);
    }

    public function testHandleCompanyUpdateHandlesException(): void
    {
        $tenantCode = 'EXCEPTION';
        $this->tenantManager->method('getTenantToken')->willReturn('valid_token');

        $this->client->method('request')->willThrowException(new \Exception('API Error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Erreur lors de la synchronisation'));

        $this->handler->handleCompanyUpdate($tenantCode);
    }
}
