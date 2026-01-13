<?php

namespace App\Tests\Unit\Controller\ProductController;

use PHPUnit\Framework\TestCase;
use App\Controller\ProductController\ProductController;
use App\UseCase\ProductUseCase\GetProductsByCommandeUseCase;
use App\UseCase\ProductUseCase\CreateProductByCommandeUseCase;
use App\UseCase\ProductUseCase\GetLandingPageProductsUseCase;
use App\UseCase\ProductUseCase\GetProductByIdUseCase;
use App\UseCase\ProductUseCase\DeleteProductUseCase;
use App\UseCase\ProductUseCase\GetAllProductsUseCase;
use App\UseCase\ProductUseCase\GetProductsByOfferUseCase;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantCacheService;
use App\Entity\Commande;
use App\Entity\Product;
use App\Dto\ProductOutputDTO;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use App\Dto\ProductDetailedOutputDTO;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use App\Enum\ProductMode;
use Doctrine\Common\Collections\ArrayCollection;

class ProductControllerTest extends TestCase
{
    private $getProductsByCommandeUseCase;
    private $createProductByCommandeUseCase;
    private $getLandingPageProductsUseCase;
    private $deleteProductUseCase;
    private $emProvider;
    private $getAllProductsUseCase;
    private $getProductsByOfferUseCase;
    private $cache;
    private $getProductByIdUseCase;
    private $controller;
    private $container;

    protected function setUp(): void
    {
        $this->getProductsByCommandeUseCase = $this->createMock(GetProductsByCommandeUseCase::class);
        $this->createProductByCommandeUseCase = $this->createMock(CreateProductByCommandeUseCase::class);
        $this->getLandingPageProductsUseCase = $this->createMock(GetLandingPageProductsUseCase::class);
        $this->deleteProductUseCase = $this->createMock(DeleteProductUseCase::class);
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->getAllProductsUseCase = $this->createMock(GetAllProductsUseCase::class);
        $this->getProductsByOfferUseCase = $this->createMock(GetProductsByOfferUseCase::class);
        $this->cache = $this->createMock(TenantCacheService::class);
        $this->getProductByIdUseCase = $this->createMock(GetProductByIdUseCase::class);

        $this->controller = new ProductController(
            $this->getProductsByCommandeUseCase,
            $this->createProductByCommandeUseCase,
            $this->getLandingPageProductsUseCase,
            $this->deleteProductUseCase,
            $this->emProvider,
            $this->getAllProductsUseCase,
            $this->getProductsByOfferUseCase,
            $this->cache,
            $this->getProductByIdUseCase
        );

        $this->container = $this->createMock(ContainerInterface::class);
        $parameterBag = $this->createMock(ContainerBagInterface::class);

        $this->container->method('has')->willReturnCallback(function ($id) {
            if ($id === 'parameter_bag') {
                return true;
            }
            if ($id === 'serializer') {
                return false;
            }
            return false;
        });

        $this->container->method('get')->willReturnCallback(function ($id) use ($parameterBag) {
            if ($id === 'parameter_bag') {
                return $parameterBag;
            }
            return null;
        });

        // Mock getParameter directly if possible, or via parameter_bag if that's how AbstractController uses it
        // AbstractController delegates getParameter to the container's parameter_bag
        $parameterBag->method('get')->with('kernel.project_dir')->willReturn('/var/www/project');

        $this->controller->setContainer($this->container);
    }

    // ... tests ...

    public function testGetProductByIdSuccess(): void
    {
        $request = new Request(['locale' => 'fr']);
        $productDTO = $this->createMock(ProductDetailedOutputDTO::class);

        $this->getProductByIdUseCase->expects($this->once())
            ->method('execute')
            ->with(1, $this->anything(), 'fr')
            ->willReturn($productDTO);

        $response = $this->controller->getProductById(1, $request);

        $this->assertEquals(JsonResponse::HTTP_OK, $response->getStatusCode());
    }

    public function testGetProductsByCommandeSuccess(): void
    {
        $commande = $this->createMock(Commande::class);
        $commande->method('getId')->willReturn(1);
        $request = new Request();

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback, $ttl, $tags) {
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            });

        $this->getProductsByCommandeUseCase->expects($this->once())
            ->method('execute')
            ->willReturn(['product1']);

        $response = $this->controller->getProductsByCommande($commande, $request);

        $this->assertEquals(JsonResponse::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals(['product1'], $content);
    }

    public function testCreateProductByCommandeSuccess(): void
    {
        $commande = $this->createMock(Commande::class);
        $request = new Request();

        $this->container->method('getParameter')
            ->with('kernel.project_dir')
            ->willReturn('/var/www/project');

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(123);
        $product->method('getPurchasePrice')->willReturn(100.0);
        $product->method('getCoefficientMultiplier')->willReturn(1.0);
        $product->method('getSlug')->willReturn('test-product');
        $product->method('getSpecifications')->willReturn([]);
        $product->method('getImage')->willReturn(null);
        $product->method('getMode')->willReturn(ProductMode::RETAIL);
        $product->method('isBookable')->willReturn(false);

        $emptyCollection = new ArrayCollection();
        $product->method('getCategory')->willReturn($emptyCollection);
        $product->method('getStyle')->willReturn(null);
        $product->method('getVariants')->willReturn($emptyCollection);
        $product->method('getTranslation')->willReturn(null);

        $this->createProductByCommandeUseCase->expects($this->once())
            ->method('execute')
            ->willReturn($product);

        $response = $this->controller->createProductByCommande($commande, $request);

        $this->assertEquals(JsonResponse::HTTP_CREATED, $response->getStatusCode());
    }

    public function testCreateProductByCommandeError(): void
    {
        $commande = $this->createMock(Commande::class);
        $request = new Request();

        $this->container->method('getParameter')
            ->with('kernel.project_dir')
            ->willReturn('/var/www/project');

        $this->createProductByCommandeUseCase->expects($this->once())
            ->method('execute')
            ->willThrowException(new \Exception('Creation failed'));

        $response = $this->controller->createProductByCommande($commande, $request);

        $this->assertEquals(JsonResponse::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Creation failed', $content['error']);
    }

    public function testDeleteProductSuccess(): void
    {
        $this->deleteProductUseCase->expects($this->once())
            ->method('execute')
            ->with(1);

        $response = $this->controller->deleteProduct(1);

        $this->assertEquals(JsonResponse::HTTP_OK, $response->getStatusCode());
    }

    public function testDeleteProductError(): void
    {
        $this->deleteProductUseCase->expects($this->once())
            ->method('execute')
            ->with(1)
            ->willThrowException(new \Exception('Delete failed'));

        $response = $this->controller->deleteProduct(1);

        $this->assertEquals(JsonResponse::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    public function testGetAllProductsSuccess(): void
    {
        $request = new Request(['locale' => 'en']);

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            });

        $this->getAllProductsUseCase->expects($this->once())
            ->method('execute')
            ->willReturn(['product_all']);

        $response = $this->controller->getAllProducts($request);

        $this->assertEquals(JsonResponse::HTTP_OK, $response->getStatusCode());
    }

    public function testGetProductsByOfferSuccess(): void
    {
        $request = new Request(['locale' => 'fr']);

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            });

        $this->getProductsByOfferUseCase->expects($this->once())
            ->method('execute')
            ->willReturn(['product_offer']);

        $response = $this->controller->getProductsByOffer('special_offer', $request);

        $this->assertEquals(JsonResponse::HTTP_OK, $response->getStatusCode());
    }

    public function testGetLandingPageProductsSuccess(): void
    {
        $request = new Request(['locale' => 'fr']);

        $this->getLandingPageProductsUseCase->expects($this->once())
            ->method('execute')
            ->willReturn(['landing_product']);

        $response = $this->controller->getLandingPageProducts($request);

        $this->assertEquals(JsonResponse::HTTP_OK, $response->getStatusCode());
    }



    public function testGetProductByIdNotFound(): void
    {
        $request = new Request(['locale' => 'fr']);

        $this->getProductByIdUseCase->expects($this->once())
            ->method('execute')
            ->willThrowException(new \Exception('Not found'));

        $response = $this->controller->getProductById(999, $request);

        $this->assertEquals(JsonResponse::HTTP_NOT_FOUND, $response->getStatusCode());
    }
}
