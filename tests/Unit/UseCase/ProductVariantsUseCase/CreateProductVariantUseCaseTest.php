<?php

namespace App\Tests\Unit\UseCase\ProductVariantsUseCase;

use App\Dto\ProductVariantInputDTO;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\ProductVariantService\ProductVariantService;
use App\UseCase\ProductVariantsUseCase\CreateProductVariantUseCase;
use PHPUnit\Framework\TestCase;

class CreateProductVariantUseCaseTest extends TestCase
{
    private $productVariantService;
    private $useCase;

    protected function setUp(): void
    {
        $this->productVariantService = $this->createMock(ProductVariantService::class);
        $this->useCase = new CreateProductVariantUseCase($this->productVariantService);
    }

    public function testExecuteDelegatesToService(): void
    {
        // 1. Setup Data
        $product = $this->createMock(Product::class);
        $inputDTO = $this->createMock(ProductVariantInputDTO::class);

        $expectedVariantDTO = $this->createMock(\App\Dto\ProductVariantDTO::class);

        // 2. Mock Expectation
        $this->productVariantService->expects($this->once())
            ->method('createProductVariant')
            ->with($product, $inputDTO)
            ->willReturn($expectedVariantDTO);

        // 3. Execute
        $result = $this->useCase->execute($product, $inputDTO);

        // 4. Verify
        $this->assertSame($expectedVariantDTO, $result);
    }
}
