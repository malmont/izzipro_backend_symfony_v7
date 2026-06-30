<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\SyncJob;
use App\Message\FinalizeSyncJob;
use App\Message\CollectionDoneBarrierJob;
use App\Message\ImportGemsuiteCollectionJob;
use App\Message\ProcessGemsuiteEntityJob;
use App\MessageHandler\ImportGemsuiteCollectionJobHandler;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ImportGemsuiteCollectionJobHandlerTest extends TestCase
{
    public function testInvokeProductsLastPageDispatchesMicroJobsAndFinalize(): void
    {
        // 1. LOGGER
        $logger = $this->createMock(LoggerInterface::class);

        // 2. TENANT MANAGER
        $tenantManager = $this->createMock(TenantConnectionManager::class);
        $tenantManager->method('findTenantById')->willReturn(['dbname' => 'tenant_db', 'code' => 'client1']);

        // 3. SYNC JOB (Fluent Interface)
        $syncJob = $this->createMock(SyncJob::class);
        $syncJob->method('setTotalItems')->willReturn($syncJob);
        $syncJob->method('getTotalItems')->willReturn(100);
        // Patch d'erreur pour voir si ça plante
        $syncJob->method('setLastError')->willReturnCallback(function ($msg) {
            fwrite(STDERR, "\n\n☠️ ERREUR : " . $msg . "\n\n");
            return $this->createMock(SyncJob::class);
        });

        // 4. DOCTRINE
        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('find')->willReturn($syncJob);

        $tenantEm = $this->createMock(EntityManagerInterface::class);
        $tenantEm->method('getRepository')->willReturn($repo);
        $tenantEm->method('flush')->willReturn(null);

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($tenantEm);

        // 5. API GEMSUITE (Scénario Produits - Dernière Page)
        // On simule 2 produits : 1 Parent (id=10, origin=10) et 1 Variante (id=11, origin=10)
        $apiResponseData = [
            'data' => [
                ['id' => 10, 'origin_product_id' => 10, 'name' => 'T-Shirt (Parent)'],
                ['id' => 11, 'origin_product_id' => 10, 'name' => 'T-Shirt Red (Variant)']
            ],
            'meta' => [
                'current_page' => 5,
                'last_page' => 5, // C'est la dernière page !
                'total' => 250
            ]
        ];

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn($apiResponseData);

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        // 6. BUS MESSENGER (Le cœur du test)
        $bus = $this->createMock(MessageBusInterface::class);

        // ANALYSE DES DISPATCHS ATTENDUS :
        // 1. Passe 1 (Parents) : Item 10 est parent -> 1 Dispatch
        // 2. Passe 2 (Variantes) : On boucle sur tout le monde -> 2 Dispatchs
        // 3. Fin de chaîne : Produits est le dernier -> 1 Dispatch (CollectionDoneBarrierJob)
        // TOTAL = 4 Dispatchs
        
        $bus->expects($this->exactly(4))
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                
                // Cas A : C'est un micro-job de traitement (ProcessHandler)
                if ($message instanceof ProcessGemsuiteEntityJob) {
                    return in_array($message->getEntityType(), ['product_parent', 'product_variant']);
                }

                // Cas B : C'est le job barrière de fin de collection (car 'products' est fini)
                if ($message instanceof CollectionDoneBarrierJob) {
                    return $message->getCompletedCollectionType() === 'products';
                }

                return false; // Message inattendu
            }))
            ->willReturn(new Envelope(new \stdClass()));

        // 7. EXECUTION
        $handler = new ImportGemsuiteCollectionJobHandler(
            $logger,
            $tenantManager,
            $emProvider,
            $bus,
            $client,
            'https://api.fake.com/'
        );

        // On lance le job pour la collection 'products', page 5
        $message = new ImportGemsuiteCollectionJob(123, 'token', 999, 'products', 5);
        
        $handler($message);
    }
}