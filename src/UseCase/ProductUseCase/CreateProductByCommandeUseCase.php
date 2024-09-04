<?php

namespace App\UseCase\ProductUseCase;
use App\Entity\Commande;
use App\Entity\Product; 
use App\Dto\ProductInputDTO;
use App\Services\ProductService\ProductService;
use Symfony\Component\HttpFoundation\Request;

class CreateProductByCommandeUseCase
{
    private ProductService $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function execute(Commande $commande, Request $request, string $uploadDir): Product
    {
        $inputDTO = new ProductInputDTO($request->request->all());
        return $this->productService->createProductByCommande($commande, $inputDTO, $request, $uploadDir);
    }
}