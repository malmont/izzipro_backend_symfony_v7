<?php

namespace App\Tests\Unit\Controller\CategoriesControlleur;

use App\Controller\CategoriesControlleur\CategoryController;
use App\Entity\Categories;
use App\UseCase\CategoriesUseCase\CountProductsByCategoryUseCase;
use App\UseCase\CategoriesUseCase\GetProductsByCategoryUseCase;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\ItemInterface;

class CategoryControllerTest extends TestCase
{
    private $getProductsUseCase;
    private $countProductsUseCase;
    private $emProvider;
    private $cache;
    private $controller;
    private $container;

    protected function setUp(): void
    {
        $this->getProductsUseCase = $this->createMock(GetProductsByCategoryUseCase::class);
        $this->countProductsUseCase = $this->createMock(CountProductsByCategoryUseCase::class);
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->cache = $this->createMock(TenantCacheService::class);

        $this->controller = new CategoryController(
            $this->getProductsUseCase,
            $this->countProductsUseCase,
            $this->emProvider,
            $this->cache
        );

        $this->container = $this->createMock(ContainerInterface::class);
        $this->controller->setContainer($this->container);
    }

    public function testGetProductsByCategory(): void
    {
        $request = new Request([
            'categories' => json_encode([1, 2]),
            'page' => 1,
            'pageSize' => 10,
            'locale' => 'en'
        ]);

        // Simuler le cache qui exécute le callback
        $this->cache->expects($this->once())
            ->method('get')
            ->will($this->returnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            }));

        // Simuler le UseCase retournant un tableau vide (pour éviter d'instancier les DTO complexes)
        $this->getProductsUseCase->expects($this->once())
            ->method('execute')
            ->with(
                'en',            // locale
                [1, 2],         // categoryIds
                null,            // keyword
                1,               // page
                10,              // pageSize
                null,            // barcode
                null,            // isWeb
                null             // isPos
            )
            ->willReturn([]);

        $this->countProductsUseCase->expects($this->once())
            ->method('execute')
            ->with('en', [1, 2], null)
            ->willReturn(50); // Total fictif

        $response = $this->controller->getProductsByCategory($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals(50, $content['meta']['total']);
        $this->assertEquals([], $content['data']);
    }

    public function testGetCategories(): void
    {
        $request = new Request(['locale' => 'fr']);

        // Simuler le cache qui exécute le callback
        $this->cache->expects($this->once())
            ->method('get')
            ->will($this->returnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            }));

        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(EntityRepository::class);

        $this->emProvider->expects($this->once())
            ->method('getEntityManager')
            ->willReturn($em);

        $em->expects($this->once())
            ->method('getRepository')
            ->with(Categories::class)
            ->willReturn($repo);

        // Retourner vide pour éviter DTO
        $repo->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $response = $this->controller->getCategories($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals([], json_decode($response->getContent(), true));
    }
}
