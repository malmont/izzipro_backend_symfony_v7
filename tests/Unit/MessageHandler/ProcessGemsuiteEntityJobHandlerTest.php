<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Categories;
use App\Entity\Entreprise;
use App\Entity\Product;
use App\Entity\Style;
use App\Message\ProcessGemsuiteEntityJob;
use App\Message\TranslateEntityJob;
use App\MessageHandler\ProcessGemsuiteEntityJobHandler;
use App\Services\GemsuiteImporterService\GemsuiteAttributeProcessor;
use App\Services\GemsuiteImporterService\GemsuiteImageUrlBuilder;
use App\Services\GemsuiteImporterService\GemsuiteStockCalculator;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\String\UnicodeString;
use ReflectionClass;

class ProcessGemsuiteEntityJobHandlerTest extends TestCase
{
    /**
     * Helper indispensable pour simuler un ID sur une entité lors du persist
     */
    private function simulateEntityId(object $entity, int $id): void
    {
        $reflection = new ReflectionClass($entity);
        while (!$reflection->hasProperty('id') && $reflection->getParentClass()) {
            $reflection = $reflection->getParentClass();
        }

        if ($reflection->hasProperty('id')) {
            $property = $reflection->getProperty('id');
            $property->setAccessible(true);
            $property->setValue($entity, $id);
        }
    }

    public function testInvokeCreatesProductAndDispatchesTranslation(): void
    {
        // 1. DATA
        $productData = [
            'id' => 100,
            'name_fr' => 'T-Shirt Super',
            'additional_fr' => 'Description',
            'price' => 19.99,
            'status' => 1,
            'sync_web' => true,
            'is_new_arrival' => false,
            'is_bestseller' => true,
            'weight' => 0.5,
            'dimensions_length' => 10,
            'dimensions_width' => 10,
            'dimensions_height' => 2,
            'category_id' => 50,
            'origin_product_id' => 100,
            'medias' => [['path' => 'img.jpg']]
        ];

        // 2. MOCKS BASIQUES
        $logger = $this->createMock(LoggerInterface::class);
        $tenantManager = $this->createMock(TenantConnectionManager::class);
        $tenantManager->method('findTenantById')->willReturn(['dbname' => 'db', 'code' => 'c1']);

        // 3. REPOSITORIES
        $productRepo = $this->createMock(EntityRepository::class);
        $productRepo->method('findOneBy')->willReturn(null); // Force la création NEW

        $styleRepo = $this->createMock(EntityRepository::class);
        $styleRepo->method('find')->willReturn(new Style());

        $catRepo = $this->createMock(EntityRepository::class);
        $catRepo->method('findOneBy')->willReturn(new Categories());

        $entRepo = $this->createMock(EntityRepository::class);
        $entRepo->method('findOneBy')->willReturn(new Entreprise());

        // 4. QUERY (DQL)
        $mockQuery = $this->createMock(AbstractQuery::class);
        $mockQuery->method('setParameter')->willReturn($mockQuery);
        $mockQuery->method('execute')->willReturn(1);

        // 5. ENTITY MANAGER (C'EST ICI LA CORRECTION 🛠️)
        $em = $this->createMock(EntityManagerInterface::class);
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('getDatabase')->willReturn('test_db');
        $em->method('getConnection')->willReturn($connection);

        $em->method('getRepository')->willReturnMap([
            [Product::class, $productRepo],
            [Style::class, $styleRepo],
            [Categories::class, $catRepo],
            [Entreprise::class, $entRepo],
        ]);
        $em->method('createQuery')->willReturn($mockQuery);

        // --- SIMULATION DE L'AUTO-INCREMENT ---
        // Quand persist($product) est appelé, on lui injecte l'ID 12345 immédiatement
        $em->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(Product::class))
            ->will($this->returnCallback(function ($entity) {
                $this->simulateEntityId($entity, 12345); // <-- LE FIX EST LÀ
            }));

        $em->expects($this->atLeastOnce())->method('flush');

        // On doit aussi mocker 'contains' pour que le code sache que l'entité est gérée
        $em->method('contains')->willReturn(true);

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($em);

        // 6. MESSENGER
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                // On vérifie que l'ID passé au job de traduction est bien celui qu'on a forcé
                return $message instanceof TranslateEntityJob
                    && $message->getEntityClass() === Product::class
                    && $message->getEntityId() === 12345; // <-- Vérification de l'ID injecté
            }))
            ->willReturn(new Envelope(new \stdClass()));

        // 7. AUTRES SERVICES
        $imgBuilder = $this->createMock(GemsuiteImageUrlBuilder::class);
        $imgBuilder->method('buildUrl')->willReturn('http://img.com/full.jpg');

        $attProcessor = $this->createMock(GemsuiteAttributeProcessor::class);

        $stockCalculator = $this->createMock(GemsuiteStockCalculator::class);
        $stockCalculator->method('calculateTotalStock')->willReturn(10);

        $slugger = $this->createMock(SluggerInterface::class);
        $slugger->method('slug')->willReturn(new UnicodeString('t-shirt-super'));

        $translationGenerator = $this->createMock(\App\Services\TranslationGeneratorService\TranslationGeneratorService::class);

        // 8. EXECUTION
        $handler = new ProcessGemsuiteEntityJobHandler(
            $logger,
            $tenantManager,
            $emProvider,
            $bus,
            $imgBuilder,
            $attProcessor,
            $stockCalculator,
            $slugger,
            $this->createMock(\App\Services\GemsuiteImporterService\GemsuiteRentalWorkaroundService::class),
            $this->createMock(\App\Services\GemsuiteImporterService\GemsuiteCompanySyncHandler::class),
            $translationGenerator
        );

        $message = new ProcessGemsuiteEntityJob(1, 999, 'product_parent', $productData);
        $handler($message);
    }

    public function testInvokeIgnoredIfInactive(): void
    {
        $inactiveData = ['id' => 100, 'origin_product_id' => 100, 'status' => 0, 'sync_web' => true, 'name_fr' => 'Off'];

        $logger = $this->createMock(LoggerInterface::class);
        $tenantManager = $this->createMock(TenantConnectionManager::class);
        $tenantManager->method('findTenantById')->willReturn(['dbname' => 'db', 'code' => 'c1']);

        $mockQuery = $this->createMock(AbstractQuery::class);
        $mockQuery->method('setParameter')->willReturn($mockQuery);

        $em = $this->createMock(EntityManagerInterface::class);
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('getDatabase')->willReturn('test_db');
        $em->method('getConnection')->willReturn($connection);
        $em->method('createQuery')->willReturn($mockQuery);
        $em->expects($this->never())->method('persist');

        $emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $emProvider->method('getEntityManager')->willReturn($em);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $handler = new ProcessGemsuiteEntityJobHandler(
            $logger,
            $tenantManager,
            $emProvider,
            $bus,
            $this->createMock(GemsuiteImageUrlBuilder::class),
            $this->createMock(GemsuiteAttributeProcessor::class),
            $this->createMock(GemsuiteStockCalculator::class),
            $this->createMock(SluggerInterface::class),
            $this->createMock(\App\Services\GemsuiteImporterService\GemsuiteRentalWorkaroundService::class),
            $this->createMock(\App\Services\GemsuiteImporterService\GemsuiteCompanySyncHandler::class),
            $this->createMock(\App\Services\TranslationGeneratorService\TranslationGeneratorService::class)
        );

        $handler(new ProcessGemsuiteEntityJob(1, 999, 'product_parent', $inactiveData));
    }
}
