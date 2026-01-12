<?php

namespace App\Tests\Unit\UseCase\ProductUseCase;

use App\Dto\ProductInputDTO;
use App\Entity\Commande;
use App\Entity\Product;
use App\Services\ProductService\ProductService;
use App\UseCase\ProductUseCase\CreateProductByCommandeUseCase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

class CreateProductByCommandeUseCaseTest extends TestCase
{
    private $productService;
    private $useCase;

    protected function setUp(): void
    {
        $this->productService = $this->createMock(ProductService::class);
        $this->useCase = new CreateProductByCommandeUseCase($this->productService);
    }

    public function testExecuteSuccess(): void
    {
        // 1. Setup Data
        $uploadDir = '/tmp/uploads';
        $requestData = [
            'name' => 'Test Product',
            'description' => 'A test description',
            'purchasePrice' => 10.0,
            'coefficientMultiplier' => 2.0
        ];

        // 2. Mock Entities & Request
        $commande = $this->createMock(Commande::class);

        $request = $this->createMock(Request::class);
        $request->request = new InputBag($requestData);

        $product = $this->createMock(Product::class);

        // 3. Mock Service Expectation
        $this->productService->expects($this->once())
            ->method('createProductByCommande')
            ->with(
                $this->equalTo($commande),
                $this->isInstanceOf(ProductInputDTO::class),
                $this->equalTo($request),
                $this->equalTo($uploadDir)
            )
            ->willReturn($product);

        // 4. Execute
        $result = $this->useCase->execute($commande, $request, $uploadDir);

        // 5. Verify
        $this->assertSame($product, $result);
    }
}
