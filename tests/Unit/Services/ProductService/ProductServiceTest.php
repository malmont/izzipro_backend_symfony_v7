<?php

namespace App\Tests\Unit\Services\ProductService;

use App\Dto\ProductDetailedOutputDTO;
use App\Dto\ProductInputDTO;
use App\Entity\Categories;
use App\Entity\Commande;
use App\Entity\Product;
use App\Entity\Style;
use App\Enum\ProductMode;
use App\Repository\ProductRepository;
use App\Services\EntityRetrieverService;
use App\Services\ProductService\ProductService;
use App\Services\TenantEntityManagerProvider;
use Cocur\Slugify\Slugify;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductServiceTest extends TestCase
{
    private $emProvider;
    private $entityRetriever;
    private $em;
    private $repo;
    private $service;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->entityRetriever = $this->createMock(EntityRetrieverService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repo = $this->createMock(ProductRepository::class);

        // Configure mock chain: Provider -> properties -> Repo
        $this->emProvider->method('getEntityManager')->willReturn($this->em);
        $this->em->method('getRepository')->with(Product::class)->willReturn($this->repo);

        $this->service = new ProductService(
            $this->emProvider,
            $this->entityRetriever
        );
    }

    private function createMockProduct(int $id = 1): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getName')->willReturn('Test Product');
        $product->method('getDescription')->willReturn('Desc');
        $product->method('getMoreinformations')->willReturn('More Info');
        $product->method('getPrice')->willReturn(10.0);
        $product->method('getImage')->willReturn('test.jpg');
        $product->method('getQuantity')->willReturn(100);
        $product->method('getCreatedAt')->willReturn(new \DateTimeImmutable());
        $product->method('getPurchasePrice')->willReturn(5.0);
        $product->method('getCoefficientMultiplier')->willReturn(2.0);
        $product->method('getBarcode')->willReturn('123456');

        // Boolean mocks for DTO
        $product->method('isIsbestseller')->willReturn(false);
        $product->method('isIsnewarrival')->willReturn(false);
        $product->method('isIsfeatured')->willReturn(false);
        $product->method('isIsspecialoffer')->willReturn(false);

        // Enum Mocking
        $product->method('getMode')->willReturn(ProductMode::RETAIL);

        // Collections
        $product->method('getVariants')->willReturn(new ArrayCollection());
        $product->method('getCategory')->willReturn(new ArrayCollection());
        // getOptionValues n'existe pas sur Product, on le retire
        $product->method('getStyle')->willReturn(null);
        $product->method('getSpecifications')->willReturn(null);
        $product->method('isBookable')->willReturn(false);

        return $product;
    }

    public function testGetProductByIdReturnsDTO(): void
    {
        $product = $this->createMockProduct(1);

        // Repository returns the product
        $this->repo->method('findByIdAndLocale')
            ->with(1, 'fr')
            ->willReturn($product);

        $dto = $this->service->getProductById(1, 'http://localhost');

        $this->assertInstanceOf(ProductDetailedOutputDTO::class, $dto);
        $this->assertEquals(1, $dto->id);
    }

    public function testGetProductByIdThrowsNotFound(): void
    {
        $this->repo->method('findByIdAndLocale')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->getProductById(999, 'http://localhost');
    }

    public function testGetAllProductsReturnsGroupedLists(): void
    {
        $product = $this->createMockProduct(1);

        // Ensure repeated calls work
        $this->repo->method('findTranslatedByCriteria')->willReturn([$product]);

        $result = $this->service->getAllProducts('http://localhost');

        $this->assertArrayHasKey('bestsellers', $result);
        $this->assertArrayHasKey('newArrivals', $result);
        $this->assertArrayHasKey('specialOffers', $result);
        $this->assertCount(1, $result['bestsellers']);
        $this->assertInstanceOf(ProductDetailedOutputDTO::class, $result['bestsellers'][0]);
    }

    public function testCreateProductByCommandePersistsEntity(): void
    {
        $commande = $this->createMock(Commande::class);
        $data = [
            'name' => 'New Product',
            'description' => 'Desc',
            'purchasePrice' => 10.0,
            'coefficientMultiplier' => 2.0,
            'category_ids' => [1]
        ];
        $inputDTO = new ProductInputDTO($data);

        $request = new Request();
        // File upload mock logic is complex, skipping file upload part for basic logic test
        // or we mock properties. Here we assume no file in request for simplicity or default behavior.

        $category = new Categories();
        $this->entityRetriever->method('findOrFail')->willReturn($category);

        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(Product::class));
        $this->em->expects($this->once())->method('flush');

        $result = $this->service->createProductByCommande($commande, $inputDTO, $request, '/tmp');

        $this->assertInstanceOf(Product::class, $result);
        $this->assertEquals('New Product', $result->getName());
        $this->assertEquals('new-product', $result->getSlug()); // Slugify verification
    }

    public function testDeleteProductRemovesEntity(): void
    {
        $product = $this->createMockProduct(10);

        $this->entityRetriever->expects($this->once())
            ->method('findOrFail')
            ->with(Product::class, 10, 'Product not found')
            ->willReturn($product);

        $this->em->expects($this->once())->method('remove')->with($product);
        $this->em->expects($this->once())->method('flush');

        $this->service->deleteProduct(10);
    }
}
