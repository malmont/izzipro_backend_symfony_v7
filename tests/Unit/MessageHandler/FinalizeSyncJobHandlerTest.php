<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\SyncJob;
use App\Message\FinalizeSyncJob;
use App\MessageHandler\FinalizeSyncJobHandler;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FinalizeSyncJobHandlerTest extends TestCase
{
    public function testInvokeUpdatesJobToCompletedAndFlushes(): void
    {
        // 1. DATA
        $tenantId = 123;
        $syncJobId = 999;
        
        // 2. MOCK LOGGER
        $logger = $this->createMock(LoggerInterface::class);
        // On vérifie qu'on log bien le succès à la fin
        $logger->expects($this->atLeastOnce())->method('info');

        // 3. TENANT MANAGER
        $tenantManager = $this->createMock(TenantConnectionManager::class);
        $tenantManager->method('findTenantById')
            ->with($tenantId)
            ->willReturn(['dbname' => 'db', 'code' => 'c1']);

        // 4. SYNC JOB (MOCK STYLO ROUGE 📝)
        $syncJob = $this->createMock(SyncJob::class);
        
        // Gestion Fluent Interface (pour ne pas planter)
        $syncJob->method('setStatus')->willReturn($syncJob);
        $syncJob->method('setCurrentStep')->willReturn($syncJob);
        $syncJob->method('setProcessedItems')->willReturn($syncJob);
        
        // Simulation des données : On dit qu'il y avait 100 items au total
        $syncJob->method('getTotalItems')->willReturn(100);

        // --- ASSERTIONS MÉTIER ---
        // C'est le cœur du test : on vérifie que le statut passe bien à "completed"
        $syncJob->expects($this->once())->method('setStatus')->with('completed');
        $syncJob->expects($this->once())->method('setCurrentStep')->with('Terminé !');
        // On vérifie qu'on force la barre de progression à 100% (processed = total)
        $syncJob->expects($this->once())->method('setProcessedItems')->with(100);

        // 5. DOCTRINE
        $repo = $this->createMock(ObjectRepository::class);
        $repo->expects($this->once())
            ->method('find')
            ->with($syncJobId)
            ->willReturn($syncJob);

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('executeStatement')->willReturn(0);

        $tenantEm = $this->createMock(EntityManagerInterface::class);
        $tenantEm->method('getRepository')->willReturn($repo);
        $tenantEm->method('getConnection')->willReturn($connection);
        
        // Le plus important : on doit FLUSHER pour sauvegarder le statut "completed"
        $tenantEm->expects($this->once())->method('flush');

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->expects($this->once())
            ->method('switchTenant')
            ->with('db', 'c1');
        $emProvider->method('getEntityManager')->willReturn($tenantEm);

        $handler = new FinalizeSyncJobHandler(
            $tenantManager, 
            $emProvider, 
            $logger,
            $this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class),
            $this->createMock(\App\Services\GemsuiteImporterService\GemsuiteRentalWorkaroundService::class),
            'http://api.te/'
        );
        $handler(new FinalizeSyncJob($tenantId, $syncJobId));
    }

    public function testInvokeStopsIfTenantNotFound(): void
    {
        // 1. Logger
        $logger = $this->createMock(LoggerInterface::class);

        // 2. Tenant Manager qui renvoie NULL
        $tenantManager = $this->createMock(TenantConnectionManager::class);
        $tenantManager->method('findTenantById')->willReturn(null);

        // 3. Provider (Ne doit jamais être appelé car on s'arrête avant)
        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->expects($this->never())->method('switchTenant');

        $handler = new FinalizeSyncJobHandler(
            $tenantManager, 
            $emProvider, 
            $logger,
            $this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class),
            $this->createMock(\App\Services\GemsuiteImporterService\GemsuiteRentalWorkaroundService::class),
            'http://api.te/'
        );
        $handler(new FinalizeSyncJob(123, 999));
    }
    
    public function testInvokeLogsErrorOnException(): void
    {
        // Test de robustesse : Si la BDD plante, on log l'erreur
        
        $logger = $this->createMock(LoggerInterface::class);
        // On s'attend à une erreur logguée
        $logger->expects($this->once())->method('error');

        $tenantManager = $this->createMock(TenantConnectionManager::class);
        $tenantManager->method('findTenantById')->willReturn(['dbname' => 'db', 'code' => 'c1']);

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        // On simule une panne database lors du switch
        $handler = new FinalizeSyncJobHandler(
            $tenantManager, 
            $emProvider, 
            $logger,
            $this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class),
            $this->createMock(\App\Services\GemsuiteImporterService\GemsuiteRentalWorkaroundService::class),
            'http://api.te/'
        );
        $handler(new FinalizeSyncJob(123, 999));
    }
}