<?php

namespace App\Tests\Unit\UseCase\ProductUseCase;

use App\Entity\Commande;
use App\Entity\Product;
use App\Services\ProductService\ProductService;
use App\UseCase\ProductUseCase\GetProductsByCommandeUseCase;
use PHPUnit\Framework\TestCase;

class GetProductsByCommandeUseCaseTest extends TestCase
{
    private $productService;
    private $useCase;

    protected function setUp(): void
    {
        $this->productService = $this->createMock(ProductService::class);
        $this->useCase = new GetProductsByCommandeUseCase($this->productService);
    }

    public function testExecuteDelegatesToService(): void
    {
        // 1. Setup Data
        $host = 'https://example.com';

        // 2. Mock Entities
        $commande = $this->createMock(Commande::class);
        $product = $this->createMock(Product::class);
        $expectedResult = [$product];

        // 3. Mock Expectation
        $this->productService->expects($this->once())
            ->method('getProductsByCommande')
            ->with($commande, $host)
            ->willReturn($expectedResult);

        // 4. Execute
        $result = $this->useCase->execute($commande, $host);

        // 5. Verify
        $this->assertSame($expectedResult, $result);
    }
}
