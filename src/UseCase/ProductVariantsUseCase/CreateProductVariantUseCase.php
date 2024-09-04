<?php

namespace App\UseCase\ProductVariantsUseCase;

use App\Entity\Product;
use App\Dto\ProductVariantInputDTO;
use App\Services\ProductVariantService\ProductVariantService;

class CreateProductVariantUseCase
{
    private $productVariantService;

    public function __construct(ProductVariantService $productVariantService)
    {
        $this->productVariantService = $productVariantService;
    }

    public function execute(Product $product, ProductVariantInputDTO $inputDTO)
    {
        return $this->productVariantService->createProductVariant($product, $inputDTO);
    }
}
