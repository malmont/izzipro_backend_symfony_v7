<?php

namespace App\UseCase\ProductUseCase;

use App\Services\ProductService\ProductService;
use App\Dto\ProductDetailedOutputDTO;

class GetProductByIdUseCase
{
    private ProductService $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function execute(int $id, string $host, string $locale = 'fr'): ProductDetailedOutputDTO
    {
        return $this->productService->getProductById($id, $host, $locale);
    }
}