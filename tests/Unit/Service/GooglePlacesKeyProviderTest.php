<?php

namespace App\Tests\Unit\Service;

use App\Entity\GooglePlacesConfig;
use App\Services\AdressService\GooglePlacesKeyProvider;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

class GooglePlacesKeyProviderTest extends TestCase
{
    // On sauvegarde l'état initial de l'environnement pour le remettre après
    private string|null $originalEnv;

    protected function setUp(): void
    {
        $this->originalEnv = $_ENV['APP_ENV'] ?? 'test';
    }

    protected function tearDown(): void
    {
        // On remet tout propre après chaque test pour ne pas polluer la suite
        $_ENV['APP_ENV'] = $this->originalEnv;
    }

    public function testGetKeyReturnsTestKeyInDevEnvironment(): void
    {
        // 1. Simulation de l'environnement DEV
        $_ENV['APP_ENV'] = 'dev';

        // 2. Simulation de la Config en BDD
        $config = $this->createMock(GooglePlacesConfig::class);
        // En dev, on s'attend à ce qu'il demande la clé TEST
        $config->method('getGoogleApiKeyTest')->willReturn('KEY_POUR_TEST');

        // 3. La chaîne de Mocks (Provider -> EM -> Repo -> Config)
        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->willReturn($config);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $provider = $this->createMock(TenantEntityManagerProvider::class);
        $provider->method('getEntityManager')->willReturn($em);

        // 4. Exécution
        $service = new GooglePlacesKeyProvider($provider);
        $key = $service->getKey();

        // 5. Vérification
        $this->assertEquals('KEY_POUR_TEST', $key);
    }

    public function testGetKeyReturnsProdKeyInProdEnvironment(): void
    {
        // 1. Simulation de l'environnement PROD
        $_ENV['APP_ENV'] = 'prod';

        // 2. Simulation de la Config
        $config = $this->createMock(GooglePlacesConfig::class);
        // En prod, on s'attend à ce qu'il demande la clé PROD
        $config->method('getGoogleApiKeyProd')->willReturn('KEY_POUR_PROD');

        // 3. Mocks
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturn($config);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $provider = $this->createMock(TenantEntityManagerProvider::class);
        $provider->method('getEntityManager')->willReturn($em);

        // 4. Exécution
        $service = new GooglePlacesKeyProvider($provider);
        $key = $service->getKey();

        $this->assertEquals('KEY_POUR_PROD', $key);
    }

    public function testGetKeyThrowsExceptionIfConfigMissing(): void
    {
        // 1. Mock du Repo qui renvoie NULL (pas de config trouvée)
        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $provider = $this->createMock(TenantEntityManagerProvider::class);
        $provider->method('getEntityManager')->willReturn($em);

        // 2. Attente de l'exception
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('GooglePlacesConfig manquant pour ce tenant');

        // 3. Exécution
        $service = new GooglePlacesKeyProvider($provider);
        $service->getKey();
    }

    public function testGetKeyReturnsEmptyStringIfKeyIsNull(): void
    {
        // 1. Env TEST
        $_ENV['APP_ENV'] = 'test';

        // 2. Mock Config avec clé null
        $config = $this->createMock(GooglePlacesConfig::class);
        $config->method('getGoogleApiKeyTest')->willReturn(null);

        // 3. Mocks
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturn($config);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $provider = $this->createMock(TenantEntityManagerProvider::class);
        $provider->method('getEntityManager')->willReturn($em);

        // 4. Exécution
        $service = new GooglePlacesKeyProvider($provider);
        $key = $service->getKey();

        // 5. Vérification
        $this->assertSame('', $key);
    }
}
