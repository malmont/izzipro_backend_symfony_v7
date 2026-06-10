<?php

namespace App\Tests\Unit\Services\GemsuiteImporterService;

use App\Entity\AddressEntreprise;
use App\Entity\Entreprise;
use App\Entity\HomeSlider;
use App\Entity\ExploreCard;
use App\Services\GemsuiteImporterService\GemsuiteCompanySyncHandler;
use App\Services\GemsuiteImporterService\GemsuiteImageUrlBuilder;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TranslationGeneratorService\TranslationGeneratorService;
use Symfony\Component\Messenger\MessageBusInterface;
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
    private $messageBus;
    private $handler;

    protected function setUp(): void
    {
        $this->client = $this->createMock(HttpClientInterface::class);
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->tenantManager = $this->createMock(TenantConnectionManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->imageUrlBuilder = $this->createMock(GemsuiteImageUrlBuilder::class);
        $this->translationGenerator = $this->createMock(TranslationGeneratorService::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $this->handler = new GemsuiteCompanySyncHandler(
            $this->client,
            $this->emProvider,
            $this->tenantManager,
            $this->logger,
            $this->imageUrlBuilder,
            $this->translationGenerator,
            $this->gemsuiteApiUrl,
            $this->messageBus
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
            'gemportal_logo' => 'logos/my-logo.png',
            'gemportal_favicon' => 'logos/my-favicon.ico',
        ];

        $response = $this->createMock(ResponseInterface::class);
        $responseData = [
            'data' => [$companyData]
        ];
        $response->method('toArray')->willReturn($responseData);

        $this->client->expects($this->once())
            ->method('request')
            ->willReturn($response);

        // Configure imageUrlBuilder
        $this->imageUrlBuilder->method('buildUrl')
            ->will($this->returnValueMap([
                ['shop', 'logos/my-logo.png', 'https://app.gem-books.com/logo.png'],
                ['shop', 'logos/my-favicon.ico', 'https://app.gem-books.com/favicon.ico'],
            ]));

        // Mock EntityManager and Repositories
        $entrepriseRepo = $this->createMock(EntityRepository::class);
        $entrepriseRepo->method('findOneBy')->willReturn(null);

        $homeSliderRepo = $this->createMock(EntityRepository::class);
        $homeSliderRepo->method('findAll')->willReturn([]);

        $exploreCardRepo = $this->createMock(EntityRepository::class);
        $exploreCardRepo->method('findAll')->willReturn([]);

        $emailConfigRepo = $this->createMock(EntityRepository::class);
        $emailConfigRepo->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')
            ->will($this->returnValueMap([
                [Entreprise::class, $entrepriseRepo],
                [HomeSlider::class, $homeSliderRepo],
                [ExploreCard::class, $exploreCardRepo],
                [\App\Entity\EmailConfiguration::class, $emailConfigRepo],
            ]));
            
        $queryMock = $this->createMock(\Doctrine\ORM\AbstractQuery::class);
        $queryMock->method('execute')->willReturn(1);
        $queryMock->method('setParameter')->willReturn($queryMock);
        $entityManager->method('createQuery')->willReturn($queryMock);

        // We expect persist for Entreprise and EmailConfiguration.
        $entityManager->expects($this->atLeast(2))
            ->method('persist')
            ->with($this->callback(function ($entity) {
                if ($entity instanceof Entreprise) {
                    $this->assertEquals('https://app.gem-books.com/logo.png', $entity->getLogo());
                    $this->assertEquals('https://app.gem-books.com/favicon.ico', $entity->getFaviconFilename());
                }
                return true;
            }));
        $entityManager->expects($this->once())->method('flush');

        $this->emProvider->method('getEntityManager')->willReturn($entityManager);

        // Only Entreprise translation is generated because banner/explore data is missing in $companyData
        $this->translationGenerator->expects($this->exactly(1))->method('generateTranslations');

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
