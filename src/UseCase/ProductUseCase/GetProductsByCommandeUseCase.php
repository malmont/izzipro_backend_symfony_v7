<?php

namespace App\UseCase\ProductUseCase;

use App\Entity\Commande;
use App\Services\ProductService\ProductService;

class GetProductsByCommandeUseCase
{
    private ProductService $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function execute(Commande $commande, string $host): array
    {
        return $this->productService->getProductsByCommande($commande, $host);
    }
}
