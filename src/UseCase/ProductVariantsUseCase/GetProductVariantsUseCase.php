<?php

namespace App\UseCase\ProductVariantsUseCase;


use App\Entity\Product;
use App\Services\ProductVariantService\ProductVariantService;

class GetProductVariantsUseCase
{
    private $productVariantService;

    public function __construct(ProductVariantService $productVariantService)
    {
        $this->productVariantService = $productVariantService;
    }

    public function execute(Product $product): array
    {
        return $this->productVariantService->getProductVariants($product);
    }
}
