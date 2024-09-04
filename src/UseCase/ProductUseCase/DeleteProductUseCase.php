<?php

namespace App\UseCase\ProductUseCase;

use App\Services\ProductService\ProductService;

class DeleteProductUseCase
{
    private ProductService $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function execute(int $id): void
    {
        $this->productService->deleteProduct($id);
    }
}
