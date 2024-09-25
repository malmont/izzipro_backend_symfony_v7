<?php

namespace App\UseCase\ProductUseCase;

use App\Services\ProductService\ProductService;

class GetAllProductsUseCase
{
    private ProductService $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function execute(string $host): array
    {
        return $this->productService->getAllProducts($host);
    }
}
