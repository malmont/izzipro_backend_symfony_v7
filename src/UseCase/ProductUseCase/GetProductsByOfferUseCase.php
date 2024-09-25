<?php

namespace App\UseCase\ProductUseCase;

use App\Services\ProductService\ProductService;

class GetProductsByOfferUseCase
{
    private ProductService $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function execute(string $offer, string $host): array
    {
        return $this->productService->getProductsByOffer($offer, $host);
    }
}
