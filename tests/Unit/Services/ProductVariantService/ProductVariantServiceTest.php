<?php

namespace App\Tests\Unit\Services\ProductVariantService;

use App\Dto\ProductVariantDTO;
use App\Dto\ProductVariantInputDTO;
use App\Entity\Color;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\Size;
use App\Services\EntityRetrieverService;
use App\Services\ProductVariantService\ProductVariantService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ProductVariantServiceTest extends TestCase
{
    private $emProvider;
    private $updateStockUseCase;
    private $entityRetriever;
    private $em;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->updateStockUseCase = $this->createMock(UpdateStockAndInventoryUseCase::class);
        $this->entityRetriever = $this->createMock(EntityRetrieverService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        // Configure provider to return our mock EM
        $this->emProvider->method('getEntityManager')->willReturn($this->em);
    }

    public function testGetProductVariantsReturnsDTOs(): void
    {
        $product = $this->createMock(Product::class);

        // MOCKER la variante plutôt que d'utiliser 'new'
        $variant = $this->createMock(ProductVariant::class);
        $variant->method('getId')->willReturn(123); // ID requis par DTO
        $variant->method('getStockQuantity')->willReturn(10);
        $variant->method('getOptionValues')->willReturn(new ArrayCollection());
        $variant->method('getColor')->willReturn(null);
        $variant->method('getSize')->willReturn(null);

        $variantsCollection = new ArrayCollection([$variant]);

        $product->method('getVariants')->willReturn($variantsCollection);

        $service = new ProductVariantService(
            $this->emProvider,
            $this->updateStockUseCase,
            $this->entityRetriever
        );

        $dtos = $service->getProductVariants($product);

        $this->assertCount(1, $dtos);
        $this->assertInstanceOf(ProductVariantDTO::class, $dtos[0]);
    }

    public function testCreateProductVariantPersistsAndUpdatesStock(): void
    {
        $product = $this->createMock(Product::class);

        $color = new Color();
        $color->setName('Red');
        $color->setCodeHexa('#FF0000');
        // Inject ID via Reflection
        $refColor = new \ReflectionObject($color);
        $propIdC = $refColor->getProperty('id');
        $propIdC->setAccessible(true);
        $propIdC->setValue($color, 1);

        $size = new Size();
        $size->setName('Large');
        $size->setCode('L');
        // Inject ID via Reflection
        $refSize = new \ReflectionObject($size);
        $propIdS = $refSize->getProperty('id');
        $propIdS->setAccessible(true);
        $propIdS->setValue($size, 2);

        // FIX 1: Constructeur avec arguments
        $inputDTO = new ProductVariantInputDTO(10, 1, 2);

        // Mock Entity Retrieval
        $this->entityRetriever->expects($this->exactly(2))
            ->method('findOrFail')
            ->will($this->returnValueMap([
                [Color::class, 1, 'Color not found', $color],
                [Size::class, 2, 'Size not found', $size],
            ]));

        // FIX 2: Simuler l'auto-increment de la BDD lors du persist
        $this->em->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(ProductVariant::class))
            ->will($this->returnCallback(function ($entity) {
                // On injecte un ID via Reflection pour que le DTO puisse être créé
                $reflection = new \ReflectionClass($entity);
                $property = $reflection->getProperty('id');
                $property->setAccessible(true);
                $property->setValue($entity, 999);
            }));

        $this->em->expects($this->once())
            ->method('flush');

        // Expect UseCase Call
        $this->updateStockUseCase->expects($this->once())
            ->method('execute')
            ->with(
                $this->isInstanceOf(ProductVariant::class),
                10,
                true
            );

        $service = new ProductVariantService(
            $this->emProvider,
            $this->updateStockUseCase,
            $this->entityRetriever
        );

        $result = $service->createProductVariant($product, $inputDTO);

        $this->assertInstanceOf(ProductVariantDTO::class, $result);
        $this->assertEquals(999, $result->id);
    }

    public function testDeleteProductVariantRemovesEntity(): void
    {
        $variant = $this->createMock(ProductVariant::class);
        $variant->method('getStockQuantity')->willReturn(5);

        // Expect UseCase (cleanup)
        $this->updateStockUseCase->expects($this->once())
            ->method('execute')
            ->with($variant, 5);

        // Expect Remove
        $this->em->expects($this->once())
            ->method('remove')
            ->with($variant);

        $this->em->expects($this->once())
            ->method('flush');

        $service = new ProductVariantService(
            $this->emProvider,
            $this->updateStockUseCase,
            $this->entityRetriever
        );

        $service->deleteProductVariant($variant);
    }
}
