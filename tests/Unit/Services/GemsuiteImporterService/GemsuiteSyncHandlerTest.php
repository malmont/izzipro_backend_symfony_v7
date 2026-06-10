<?php

namespace App\Tests\Unit\Services\GemsuiteImporterService;

use App\Entity\Categories;
use App\Entity\Entreprise;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\GemsuiteImporterService\GemsuiteAttributeProcessor;
use App\Services\GemsuiteImporterService\GemsuiteImageUrlBuilder;
use App\Services\GemsuiteImporterService\GemsuiteClientManager;
use App\Services\GemsuiteImporterService\GemsuiteStockCalculator;
use App\Services\GemsuiteImporterService\GemsuiteSyncHandler;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TranslationGeneratorService\TranslationGeneratorService;
use Symfony\Component\Messenger\MessageBusInterface;
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
    private $clientManager;
    private $rentalWorkaround;
    private $messageBus;
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
        $this->clientManager = $this->createMock(GemsuiteClientManager::class);
        $this->rentalWorkaround = $this->createMock(\App\Services\GemsuiteImporterService\GemsuiteRentalWorkaroundService::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);

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
            $this->gemsuiteApiUrl,
            $this->clientManager,
            $this->rentalWorkaround,
            $this->messageBus
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

    /*
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
            'web_display' => true,
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
            ->willReturnCallback(function ($method, $url) use ($matcher, $respProd, $respCat) {
                if (strpos($url, 'categories') !== false) return $respCat;
                return $respProd;
            });

        // Mock Mock Entity Manager Logic
        $repoProduct = $this->createMock(EntityRepository::class);
        $repoProduct->method('findOneBy')->willReturn(null); // New Product

        $repoCategory = $this->createMock(EntityRepository::class);
        $repoCategory->method('findOneBy')->willReturn(new Categories()); // Existing Category for Active check

        $repoStyle = $this->createMock(EntityRepository::class);
        $repoStyle->method('find')->willReturn(null);

        $repoEntreprise = $this->createMock(EntityRepository::class);
        $entreprise = $this->createMock(Entreprise::class);
        $entreprise->method('getGemsuiteIdentifier')->willReturn('COMP-1');
        $repoEntreprise->method('findOneBy')->willReturn($entreprise);

        $repoSaleUnit = $this->createMock(EntityRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(function ($class) use ($repoEntreprise, $repoProduct, $repoCategory, $repoStyle, $repoSaleUnit) {
            if ($class === Entreprise::class) return $repoEntreprise;
            if ($class === Product::class) return $repoProduct;
            if ($class === Categories::class) return $repoCategory;
            if ($class === Style::class || $class === \App\Entity\ShippingClass::class) return $repoStyle;
            if ($class === \App\Entity\SaleUnit::class) return $repoSaleUnit;
            return null;
        });

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
    */

    public function testHandleProductUpdateDeactivatesInactiveProduct(): void
    {
        $this->tenantManager->method('getTenantToken')->willReturn('token');

        // Inactive Data
        $gemProductData = [
            'id' => 202,
            'origin_product_id' => 202,
            'name_fr' => 'Inactive',
            'status' => 0, // Inactive
            'web_display' => true,
        ];

        $respProd = $this->createMock(ResponseInterface::class);
        $respProd->method('toArray')->willReturn(['data' => $gemProductData]);

        $respCat = $this->createMock(ResponseInterface::class);
        $respCat->method('toArray')->willReturn(['data' => []]);

        $this->client->method('request')->willReturnCallback(function ($method, $url) use ($respProd, $respCat) {
            if (strpos($url, 'categories') !== false) return $respCat;
            return $respProd;
        });

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
        $this->tenantManager->method('findTenantByCode')->with('T1')->willReturn(['id' => 123, 'code' => 'T1']);

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

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->will($this->returnValueMap([
            [Entreprise::class, $repoEntreprise],
            [Categories::class, $repoCategory]
        ]));

        $this->emProvider->method('getEntityManager')->willReturn($em);

        $em->expects($this->once())->method('persist');
        $em->expects($this->exactly(2))->method('flush');

        // Expect job dispatching
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) use ($categoryId) {
                return $message instanceof \App\Message\SyncCategoryProductsJob
                    && $message->getTenantId() === 123
                    && $message->getGemsuiteCategoryId() === $categoryId;
            }))
            ->willReturn(new \Symfony\Component\Messenger\Envelope(new \stdClass()));

        $this->handler->handleCategoryUpdate($tenantCode, $categoryId);
    }

    public function testHandleClientUpdateDelegatesToManager(): void
    {
        $this->tenantManager->method('getTenantToken')->willReturn('token');
        $tenantCode = 'T1';
        $clientId = 999;

        $this->clientManager->expects($this->once())
            ->method('updateClientGemsuite')
            ->with($tenantCode, $clientId);

        $this->handler->handleClientUpdate($tenantCode, $clientId);
    }

    public function testSyncCategoryProducts(): void
    {
        $tenantCode = 'T1';
        $categoryId = 55;
        $token = 'token';

        $this->tenantManager->method('getTenantToken')->willReturn($token);

        $category = new Categories();
        $category->setGemsuiteCategoryId($categoryId);
        $category->setExternalShippingClassId(10);
        $category->setIsRentalCategory(false);

        $repoCategory = $this->createMock(EntityRepository::class);
        $repoCategory->method('findAll')->willReturn([$category]);
        $repoCategory->method('findOneBy')->willReturn($category);

        $repoEntreprise = $this->createMock(EntityRepository::class);
        $entreprise = new Entreprise();
        $entreprise->setGemsuiteIdentifier('COMP-1');
        $repoEntreprise->method('findOneBy')->willReturn($entreprise);

        $repoProduct = $this->createMock(EntityRepository::class);
        $repoProduct->method('findOneBy')->willReturn(null);

        $repoRentalPack = $this->createMock(EntityRepository::class);
        $repoRentalPack->method('findOneBy')->willReturn(null);

        $repoStyle = $this->createMock(EntityRepository::class);
        $repoSaleUnit = $this->createMock(EntityRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(function ($class) use ($repoCategory, $repoEntreprise, $repoProduct, $repoRentalPack, $repoStyle, $repoSaleUnit) {
            if ($class === Categories::class) return $repoCategory;
            if ($class === Entreprise::class) return $repoEntreprise;
            if ($class === Product::class) return $repoProduct;
            if ($class === \App\Entity\RentalPack::class) return $repoRentalPack;
            if ($class === \App\Entity\Style::class || $class === \App\Entity\ShippingClass::class) return $repoStyle;
            if ($class === \App\Entity\SaleUnit::class) return $repoSaleUnit;
            return null;
        });

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('getDatabase')->willReturn('db_T1');
        $em->method('getConnection')->willReturn($connection);

        $this->emProvider->method('getEntityManager')->willReturn($em);

        $gemProductsData = [
            'data' => [
                [
                    'id' => 101,
                    'origin_product_id' => 101,
                    'name_fr' => 'Product 1',
                    'status' => 1,
                    'web_display' => true,
                    'price' => 10.0,
                    'category_id' => 55,
                    'default_quantity' => 10,
                ]
            ],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1
            ]
        ];

        $resp = $this->createMock(ResponseInterface::class);
        $resp->method('toArray')->willReturn($gemProductsData);
        $this->client->method('request')->willReturn($resp);

        $this->slugger->method('slug')->willReturn(new UnicodeString('product-1'));
        $this->stockCalculator->method('calculateTotalStock')->willReturn(10);

        $em->expects($this->atLeastOnce())->method('persist');
        $em->expects($this->once())->method('flush');

        $this->handler->syncCategoryProducts($tenantCode, $categoryId);
    }

    public function testSyncCategoryProductsLocalDeactivation(): void
    {
        $tenantCode = 'T1';
        $categoryId = 55;
        $token = 'token';

        $this->tenantManager->method('getTenantToken')->willReturn($token);

        $category = new Categories();
        $category->setGemsuiteCategoryId($categoryId);
        $category->setSyncWeb(false); // <--- sync_web is false

        // Create a mock product belonging to this category
        $product = new Product();
        $product->setGemsuiteProductId(101);
        $product->setGemsuiteWebDisplay(false); // <--- gemsuiteWebDisplay is false (should be deactivated)
        $product->setIsWeb(true);
        $category->addProduct($product);

        $repoCategory = $this->createMock(EntityRepository::class);
        $repoCategory->method('findOneBy')->with(['gemsuiteCategoryId' => $categoryId])->willReturn($category);

        $repoRentalPack = $this->createMock(EntityRepository::class);
        $repoRentalPack->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(function ($class) use ($repoCategory, $repoRentalPack) {
            if ($class === Categories::class) return $repoCategory;
            if ($class === \App\Entity\RentalPack::class) return $repoRentalPack;
            return null;
        });

        $this->emProvider->method('getEntityManager')->willReturn($em);

        // We expect NO HTTP calls to the client (remote API)!
        $this->client->expects($this->never())->method('request');

        $em->expects($this->once())->method('flush');

        $this->handler->syncCategoryProducts($tenantCode, $categoryId);

        // Verify that the product has been deactivated locally
        $this->assertFalse($product->isWeb());
    }
}
