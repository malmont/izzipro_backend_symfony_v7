<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\TranslatableInterface;
use App\Message\TranslateEntityJob;
use App\MessageHandler\TranslateEntityJobHandler;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TranslationGeneratorService\TranslationGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

class TranslateEntityJobHandlerTest extends TestCase
{
    public function testInvokeCallsGeneratorAndFlushesForTranslatableEntity(): void
    {
        // 1. DATA
        $tenantId = 123;
        $entityId = 999;
        $entityClass = 'App\Entity\Product'; // Nom de classe fictif pour le test

        // 2. MOCKS BASIQUES
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('info'); // Log succès attendu

        $tenantManager = $this->createMock(TenantConnectionManager::class);
        $tenantManager->method('findTenantById')->willReturn(['dbname' => 'db', 'code' => 'c1']);

        // 3. ENTITÉ TRADUISIBLE (LE CŒUR DU SUCCÈS)
        // On crée un Mock qui implémente l'interface requise par ton Handler
        $mockEntity = $this->createMock(TranslatableInterface::class);

        // 4. DOCTRINE
        $repo = $this->createMock(ObjectRepository::class);
        $repo->expects($this->once())
            ->method('find')
            ->with($entityId)
            ->willReturn($mockEntity);

        $tenantEm = $this->createMock(EntityManagerInterface::class);
        $tenantEm->method('getRepository')->with($entityClass)->willReturn($repo);
        
        // ASSERTION 1 : On doit sauvegarder les traductions générées
        $tenantEm->expects($this->once())->method('flush');

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($tenantEm);

        // 5. SERVICE DE TRADUCTION
        $generator = $this->createMock(TranslationGeneratorService::class);
        
        // ASSERTION 2 : Le générateur DOIT être appelé avec notre entité
        $generator->expects($this->once())
            ->method('generateTranslations')
            ->with($mockEntity);

        // 6. EXECUTION
        $handler = new TranslateEntityJobHandler($logger, $tenantManager, $emProvider, $generator);
        
        $handler(new TranslateEntityJob($tenantId, $entityClass, $entityId));
    }

    public function testInvokeIgnoresNonTranslatableEntity(): void
    {
        // SCÉNARIO : L'entité existe, mais n'a pas l'interface TranslatableInterface
        
        $entityClass = 'App\Entity\User'; // Exemple d'entité non traduisible
        
        // 1. Logger : Doit recevoir un WARNING
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        // 2. Entité NON Traduisible (Juste un objet standard)
        $simpleEntity = new \stdClass(); 

        // 3. Doctrine
        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('find')->willReturn($simpleEntity);

        $tenantEm = $this->createMock(EntityManagerInterface::class);
        $tenantEm->method('getRepository')->willReturn($repo);
        
        // IMPORTANT : Pas de flush car rien n'a changé
        $tenantEm->expects($this->never())->method('flush');

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('switchTenant'); // Juste pour que ça ne plante pas
        $emProvider->method('getEntityManager')->willReturn($tenantEm);
        
        $tenantManager = $this->createMock(TenantConnectionManager::class);
        $tenantManager->method('findTenantById')->willReturn(['dbname' => 'db', 'code' => 'c1']);

        // 4. Generator : NE DOIT PAS ÊTRE APPELÉ
        $generator = $this->createMock(TranslationGeneratorService::class);
        $generator->expects($this->never())->method('generateTranslations');

        // 5. Execution
        $handler = new TranslateEntityJobHandler($logger, $tenantManager, $emProvider, $generator);
        $handler(new TranslateEntityJob(1, $entityClass, 99));
    }

    public function testInvokeThrowsUnrecoverableExceptionIfEntityNotFound(): void
    {
        // SCÉNARIO : L'ID n'existe pas en base -> UnrecoverableMessageHandlingException (Pas de retry Messenger)
        
        $logger = $this->createMock(LoggerInterface::class);
        // On s'attend à un warning puis une erreur logguée
        $logger->expects($this->once())->method('warning');
        $logger->expects($this->once())->method('error');

        $tenantManager = $this->createMock(TenantConnectionManager::class);
        $tenantManager->method('findTenantById')->willReturn(['dbname' => 'db', 'code' => 'c1']);

        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('find')->willReturn(null); // <-- INTROUVABLE

        $tenantEm = $this->createMock(EntityManagerInterface::class);
        $tenantEm->method('getRepository')->willReturn($repo);

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($tenantEm);

        $generator = $this->createMock(TranslationGeneratorService::class);

        // Assertion : UnrecoverableMessageHandlingException doit remonter pour stopper les retries Messenger
        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('non trouvée');

        $handler = new TranslateEntityJobHandler($logger, $tenantManager, $emProvider, $generator);
        $handler(new TranslateEntityJob(1, 'App\Entity\Product', 999));
    }
}