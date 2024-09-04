<?php

namespace App\UseCase\ProductVariantsUseCase;

use App\Entity\ProductVariant;
use App\Services\ProductVariantService\ProductVariantService;

class DeleteProductVariantUseCase
{
    private $productVariantService;

    public function __construct(ProductVariantService $productVariantService)
    {
        $this->productVariantService = $productVariantService;
    }

    public function execute(ProductVariant $variant): void
    {
        $this->productVariantService->deleteProductVariant($variant);
    }
}
