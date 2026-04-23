<?php
// src/UseCase/ProductUseCase/GetProductBySlugUseCase.php

namespace App\UseCase\ProductUseCase;

use App\Services\ProductService\ProductService;
use App\Dto\ProductDetailedOutputDTO;

class GetProductBySlugUseCase
{
    private ProductService $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function execute(string $slug, string $host, string $locale = 'fr'): ProductDetailedOutputDTO
    {
        return $this->productService->getProductBySlug($slug, $host, $locale);
    }
}
