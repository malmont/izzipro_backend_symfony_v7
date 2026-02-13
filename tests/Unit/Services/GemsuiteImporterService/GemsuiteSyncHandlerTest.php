<?php

namespace App\Tests\Unit\Services\GemsuiteImporterService;

use App\Entity\Categories;
use App\Entity\Entreprise;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\GemsuiteImporterService\GemsuiteAttributeProcessor;
use App\Services\GemsuiteImporterService\GemsuiteImageUrlBuilder;
use App\Services\GemsuiteImporterService\GemsuiteStockCalculator;
use App\Services\GemsuiteImporterService\GemsuiteSyncHandler;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TranslationGeneratorService\TranslationGeneratorService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\String\UnicodeString;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GemsuiteSyncHandlerTest extends TestCase
{
    private $client;
    private $emProvider;
    private $tenantManager;
    private $slugger;
    private $logger;
    private $imageUrlBuilder;
    private $translationGenerator;
    private $attributeProcessor;
    private $stockCalculator;
    private $gemsuiteApiUrl = 'https://api.example.com/';
    private $handler;

    protected function setUp(): void
    {
        $this->client = $this->createMock(HttpClientInterface::class);
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->tenantManager = $this->createMock(TenantConnectionManager::class);
        $this->slugger = $this->createMock(SluggerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->imageUrlBuilder = $this->createMock(GemsuiteImageUrlBuilder::class);
        $this->translationGenerator = $this->createMock(TranslationGeneratorService::class);
        $this->attributeProcessor = $this->createMock(GemsuiteAttributeProcessor::class);
        $this->stockCalculator = $this->createMock(GemsuiteStockCalculator::class);

        $this->handler = new GemsuiteSyncHandler(
            $this->client,
            $this->emProvider,
            $this->tenantManager,
            $this->slugger,
            $this->logger,
            $this->imageUrlBuilder,
            $this->translationGenerator,
            $this->attributeProcessor,
            $this->stockCalculator,
            $this->gemsuiteApiUrl
        );
    }

    public function testHandleProductUpdateAbortsValidation(): void
    {
        // 1. No Token
        $this->tenantManager->expects($this->once())->method('getTenantToken')->willReturn(null);
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('Token manquant'));

        $this->handler->handleProductUpdate('T1', 123);
    }

    public function testHandleProductUpdateApiFail(): void
    {
        $this->tenantManager->method('getTenantToken')->willReturn('token');

        // Mock fetch failure (return null)
        $resp = $this->createMock(ResponseInterface::class);
        $resp->method('toArray')->willThrowException(new \Exception('fail'));
        $this->client->method('request')->willReturn($resp);

        $this->logger->expects($this->once())->method('error')->with($this->stringContains('API Fail'));
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('introuvable'));

        $this->handler->handleProductUpdate('T1', 123);
    }

    public function testHandleProductUpdateSyncsActiveParentProduct(): void
    {
        $tenantCode = 'T1';
        $productId = 101;
        $token = 'token';

        $this->tenantManager->method('getTenantToken')->willReturn($token);

        // API Data for Product
        $gemProductData = [
            'id' => 101,
            'origin_product_id' => 101, // Is Parent
            'name_fr' => 'Test Product',
            'status' => 1,
            'sync_web' => true,
            'price' => 10.0,
            'category_id' => 5,
            'default_quantity' => 100,
            'quantite' => [],
            'attributs' => [],
            'variantes' => [],
        ];

        // API Data for Categories (called inside importCategories)
        $gemCategoryData = [
            'data' => [
                ['id' => 5, 'name_fr' => 'Cat 1', 'status' => 1, 'sync_web' => true]
            ]
        ];

        // Mock HTTP Calls sequentially
        $respProd = $this->createMock(ResponseInterface::class);
        $respProd->method('toArray')->willReturn(['data' => $gemProductData]);

        $respCat = $this->createMock(ResponseInterface::class);
        $respCat->method('toArray')->willReturn($gemCategoryData);

        $matcher = $this->exactly(2);
        $this->client->expects($matcher)
            ->method('request')
            ->willReturnCallback(function () use ($matcher, $respProd, $respCat) {
                if ($matcher->getInvocationCount() === 1) return $respProd; // Fetch Product
                return $respCat; // Import Categories
            });

        // Mock Mock Entity Manager Logic
        $repoProduct = $this->createMock(EntityRepository::class);
        $repoProduct->method('findOneBy')->willReturn(null); // New Product

        $repoCategory = $this->createMock(EntityRepository::class);
        $repoCategory->method('findOneBy')->willReturn(null); // New Category

        $repoStyle = $this->createMock(EntityRepository::class);
        $repoStyle->method('find')->willReturn(null);

        $repoEntreprise = $this->createMock(EntityRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->will($this->returnValueMap([
            [Entreprise::class, $repoEntreprise],
            [Product::class, $repoProduct],
            [Categories::class, $repoCategory],
            [\App\Entity\Style::class, $repoStyle],
        ]));

        $this->emProvider->method('getEntityManager')->willReturn($em);

        $this->slugger->method('slug')->willReturn(new UnicodeString('test-product'));
        $this->stockCalculator->method('calculateTotalStock')->willReturn(100);

        // Expect Persist calls
        // 1. Category
        // 2. Product
        // 3. Shipping (implicit)
        // 4. Default Variant (implicit for simple product)
        $em->expects($this->atLeast(3))->method('persist');
        $em->expects($this->atLeastOnce())->method('flush');

        $this->handler->handleProductUpdate($tenantCode, $productId);
    }

    public function testHandleProductUpdateDeactivatesInactiveProduct(): void
    {
        $this->tenantManager->method('getTenantToken')->willReturn('token');

        // Inactive Data
        $gemProductData = [
            'id' => 202,
            'origin_product_id' => 202,
            'name_fr' => 'Inactive',
            'status' => 0, // Inactive
            'sync_web' => true,
        ];

        $resp = $this->createMock(ResponseInterface::class);
        $resp->method('toArray')->willReturn(['data' => $gemProductData]);
        $this->client->method('request')->willReturn($resp);

        $repoProduct = $this->createMock(EntityRepository::class);
        $product = new Product();
        $product->setIsWeb(true);
        $repoProduct->method('findOneBy')->willReturn($product);

        $repoEntreprise = $this->createMock(EntityRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->will($this->returnValueMap([
            [Entreprise::class, $repoEntreprise],
            [Product::class, $repoProduct],
        ]));
        $this->emProvider->method('getEntityManager')->willReturn($em);

        $this->logger->expects($this->atLeastOnce())
            ->method('info');
        // ->with($this->stringContains('Désactivation locale'));

        $this->handler->handleProductUpdate('T1', 202);

        $this->assertFalse($product->isWeb());
    }

    public function testHandleCategoryUpdate(): void
    {
        $tenantCode = 'T1';
        $categoryId = 55;
        $this->tenantManager->method('getTenantToken')->willReturn('token');

        $gemCategoryData = [
            'data' => [
                ['id' => 55, 'name_fr' => 'Updated Cat', 'status' => 1, 'sync_web' => true]
            ]
        ];

        $resp = $this->createMock(ResponseInterface::class);
        $resp->method('toArray')->willReturn($gemCategoryData);
        $this->client->method('request')->willReturn($resp);

        $repoCategory = $this->createMock(EntityRepository::class);
        $repoEntreprise = $this->createMock(EntityRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->will($this->returnValueMap([
            [Entreprise::class, $repoEntreprise],
            [Categories::class, $repoCategory]
        ]));

        $this->emProvider->method('getEntityManager')->willReturn($em);

        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $this->handler->handleCategoryUpdate($tenantCode, $categoryId);
    }
}
