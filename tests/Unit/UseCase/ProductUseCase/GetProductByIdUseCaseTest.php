<?php

namespace App\Tests\Unit\UseCase\ProductUseCase;

use App\Dto\ProductDetailedOutputDTO;
use App\Services\ProductService\ProductService;
use App\UseCase\ProductUseCase\GetProductByIdUseCase;
use PHPUnit\Framework\TestCase;

class GetProductByIdUseCaseTest extends TestCase
{
    private $productService;
    private $useCase;

    protected function setUp(): void
    {
        $this->productService = $this->createMock(ProductService::class);
        $this->useCase = new GetProductByIdUseCase($this->productService);
    }

    public function testExecuteDelegatesToService(): void
    {
        // 1. Setup Data
        $productId = 123;
        $host = 'https://example.com';
        $locale = 'en';

        // 2. Mock DTO
        // Since the UseCase just passes through the result, mocking the return object is sufficient.
        $dto = $this->createMock(ProductDetailedOutputDTO::class);

        // 3. Mock Expectation
        $this->productService->expects($this->once())
            ->method('getProductById')
            ->with($productId, $host, $locale)
            ->willReturn($dto);

        // 4. Execute
        $result = $this->useCase->execute($productId, $host, $locale);

        // 5. Verify
        $this->assertSame($dto, $result);
    }

    public function testExecuteUseDefaultLocale(): void
    {
        $productId = 456;
        $host = 'https://example.com';

        $dto = $this->createMock(ProductDetailedOutputDTO::class);

        $this->productService->expects($this->once())
            ->method('getProductById')
            ->with($productId, $host, 'fr') // Expect default 'fr'
            ->willReturn($dto);

        $result = $this->useCase->execute($productId, $host);

        $this->assertSame($dto, $result);
    }
}
