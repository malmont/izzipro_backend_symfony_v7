<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\SyncJob;
use App\Message\StartGemsuiteImportJob;
use App\MessageHandler\StartGemsuiteImportJobHandler;
use App\Services\DefaultAssetSynchronizer;
use App\Services\GemsuiteImporterService\GemsuiteImageUrlBuilder;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TranslationGeneratorService\TranslationGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class StartGemsuiteImportJobHandlerTest extends TestCase
{
    public function testInvokeCreatesShellAndDispatchesNextJob(): void
    {
        // 1. LOGGER
        $logger = $this->createMock(LoggerInterface::class);

        // 2. TENANT MANAGER
        $tenantManager = $this->createMock(TenantConnectionManager::class);
        $tenantManager->method('findTenantById')->willReturn(['dbname' => 'tenant_db', 'code' => 'client1']);

        // 3. SYNC JOB (MODE "PAS DE BLOCAGE" MAIS "AFFICHE L'ERREUR")
        $syncJob = $this->createMock(SyncJob::class);
        
        // On autorise tout (comme ça on ne plante pas sur "called more than once")
        $syncJob->method('setStatus')->willReturn($syncJob);
        $syncJob->method('setCurrentStep')->willReturn($syncJob);
        
        // ☠️ ON AFFICHE LA VRAIE ERREUR ICI ☠️
        $syncJob->method('setLastError')->willReturnCallback(function ($msg) {
            fwrite(STDERR, "\n\n☠️☠️☠️ LE COUPABLE EST ICI : " . $msg . "\n\n");
            return $this->createMock(SyncJob::class);
        });

        // 4. DOCTRINE
        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('find')->willReturn($syncJob);
        $repo->method('findOneBy')->willReturn(null); 

        $tenantEm = $this->createMock(EntityManagerInterface::class);
        $tenantEm->method('getRepository')->willReturn($repo);
        $tenantEm->method('persist')->willReturn(null);
        $tenantEm->method('flush')->willReturn(null);

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($tenantEm);

        // 5. SERVICES
        $assetSync = $this->createMock(DefaultAssetSynchronizer::class);
        $transGen = $this->createMock(TranslationGeneratorService::class);
        $imgBuilder = $this->createMock(GemsuiteImageUrlBuilder::class);
        $imgBuilder->method('buildUrl')->willReturn('https://fake-url.com/image.jpg');

        // 6. API GEMSUITE (DONNÉES COMPLÈTES QUI FONT PLANTER)
        // C'est ce bloc de données précis qui cause le crash quand il est traité
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'data' => [[
                'nom' => 'Ma Super Entreprise',
                'email' => 'contact@test.com',
                'website_link' => 'https://gem-books.com/client123',
                // Bloc complet
                'website_logo1' => 'logo.jpg',
                'website_banner' => 'banner.jpg',
                'website_about_intro' => 'About Us',
                'website_terms' => 'Terms',
                'website_conf' => 'Confidentiality',
                'website_intro_text1' => 'Welcome',
                'website_intro_text2' => 'Description',
                'tps' => '123456789',
                'federal' => '987654321',
                'adresse' => '123 Main St',
                'ville' => 'Montreal',
                'prov' => 'QC',
                'cp' => 'H1H 1H1',
                'country' => 1,
                'tel' => '555-1234'
            ]]
        ]);
        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        // 7. BUS
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        // 8. EXECUTION
        $handler = new StartGemsuiteImportJobHandler(
            $logger, $tenantManager, $emProvider, $assetSync, $transGen, $imgBuilder, $client, $bus, 'api_url'
        );

        $handler(new StartGemsuiteImportJob(123, 'token', 999));
        
        $this->assertTrue(true);
    }
}