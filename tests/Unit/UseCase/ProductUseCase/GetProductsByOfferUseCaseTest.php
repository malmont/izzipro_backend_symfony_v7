<?php

namespace App\Tests\Unit\UseCase\ProductUseCase;

use App\Dto\ProductDetailedOutputDTO;
use App\Services\ProductService\ProductService;
use App\UseCase\ProductUseCase\GetProductsByOfferUseCase;
use PHPUnit\Framework\TestCase;

class GetProductsByOfferUseCaseTest extends TestCase
{
    private $productService;
    private $useCase;

    protected function setUp(): void
    {
        $this->productService = $this->createMock(ProductService::class);
        $this->useCase = new GetProductsByOfferUseCase($this->productService);
    }

    public function testExecuteDelegatesToService(): void
    {
        // 1. Setup Data
        $offer = 'special_offer';
        $host = 'https://example.com';
        $locale = 'en';

        // 2. Mock Entities
        // The service returns an array of DTOs (or products mapped to DTOs), strictly speaking execute retrieves array.
        // Looking at the UseCase signature: public function execute(...): array
        $expectedResult = [$this->createMock(ProductDetailedOutputDTO::class)];

        // 3. Mock Expectation
        $this->productService->expects($this->once())
            ->method('getProductsByOffer')
            ->with($offer, $host, $locale)
            ->willReturn($expectedResult);

        // 4. Execute
        $result = $this->useCase->execute($offer, $host, $locale);

        // 5. Verify
        $this->assertSame($expectedResult, $result);
    }

    public function testExecuteUseDefaultLocale(): void
    {
        $offer = 'new_arrival';
        $host = 'https://example.com';

        $expectedResult = [];

        $this->productService->expects($this->once())
            ->method('getProductsByOffer')
            ->with($offer, $host, 'fr') // Expect default 'fr'
            ->willReturn($expectedResult);

        $result = $this->useCase->execute($offer, $host);

        $this->assertSame($expectedResult, $result);
    }
}
