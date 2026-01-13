<?php

namespace App\Tests\Unit\Controller\ProductVariantController;

use PHPUnit\Framework\TestCase;
use App\Controller\ProductVariantController\ProductVariantController;
use App\UseCase\ProductVariantsUseCase\GetProductVariantsUseCase;
use App\UseCase\ProductVariantsUseCase\CreateProductVariantUseCase;
use App\UseCase\ProductVariantsUseCase\DeleteProductVariantUseCase;
use App\Services\TenantCacheService;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Dto\ProductVariantInputDTO;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ProductVariantControllerTest extends TestCase
{
    private $getProductVariantsUseCase;
    private $createProductVariantUseCase;
    private $deleteProductVariantUseCase;
    private $cache;
    private $controller;
    private $container;

    protected function setUp(): void
    {
        $this->getProductVariantsUseCase = $this->createMock(GetProductVariantsUseCase::class);
        $this->createProductVariantUseCase = $this->createMock(CreateProductVariantUseCase::class);
        $this->deleteProductVariantUseCase = $this->createMock(DeleteProductVariantUseCase::class);
        $this->cache = $this->createMock(TenantCacheService::class);

        $this->controller = new ProductVariantController(
            $this->getProductVariantsUseCase,
            $this->createProductVariantUseCase,
            $this->deleteProductVariantUseCase,
            $this->cache
        );

        $this->container = $this->createMock(ContainerInterface::class);
        $this->controller->setContainer($this->container);
    }

    public function testGetProductVariantsSuccess(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(1);

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            });

        $this->getProductVariantsUseCase->expects($this->once())
            ->method('execute')
            ->with($product)
            ->willReturn(['variant1', 'variant2']);

        $response = $this->controller->getProductVariants($product);

        $this->assertEquals(JsonResponse::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals(['variant1', 'variant2'], $content);
    }

    public function testCreateProductVariantSuccess(): void
    {
        $product = $this->createMock(Product::class);
        $data = [
            'stockQuantity' => 10,
            'color' => ['id' => 1],
            'size' => ['id' => 2]
        ];
        $request = new Request([], [], [], [], [], [], json_encode($data));

        $this->createProductVariantUseCase->expects($this->once())
            ->method('execute')
            ->with($product, $this->isInstanceOf(ProductVariantInputDTO::class))
            ->willReturn(['id' => 1, 'stockQuantity' => 10]);

        $response = $this->controller->createProductVariant($product, $request);

        $this->assertEquals(JsonResponse::HTTP_CREATED, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals(1, $content['id']);
    }

    public function testDeleteProductVariantSuccess(): void
    {
        $variant = $this->createMock(ProductVariant::class);

        $this->deleteProductVariantUseCase->expects($this->once())
            ->method('execute')
            ->with($variant);

        $response = $this->controller->deleteProductVariant($variant);

        $this->assertEquals(JsonResponse::HTTP_NO_CONTENT, $response->getStatusCode());
    }
}
