<?php

namespace App\Controller\ProductVariantController;


use App\Dto\ProductVariantInputDTO;
use App\UseCase\ProductVariantsUseCase\GetProductVariantsUseCase;
use App\UseCase\ProductVariantsUseCase\CreateProductVariantUseCase;
use App\UseCase\ProductVariantsUseCase\DeleteProductVariantUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Entity\Product;
use App\Entity\ProductVariant;

class ProductVariantController extends AbstractController
{
    private $getProductVariantsUseCase;
    private $createProductVariantUseCase;
    private $deleteProductVariantUseCase;

    public function __construct(
        GetProductVariantsUseCase $getProductVariantsUseCase,
        CreateProductVariantUseCase $createProductVariantUseCase,
        DeleteProductVariantUseCase $deleteProductVariantUseCase
    ) {
        $this->getProductVariantsUseCase = $getProductVariantsUseCase;
        $this->createProductVariantUseCase = $createProductVariantUseCase;
        $this->deleteProductVariantUseCase = $deleteProductVariantUseCase;
    }

    #[Route('/api/products/{id}/variants', name: 'get_product_variants', methods: ['GET'])]
    public function getProductVariants(Product $product): JsonResponse
    {
        $variants = $this->getProductVariantsUseCase->execute($product);
        return $this->json($variants, JsonResponse::HTTP_OK);
    }

    #[Route('/api/products/{id}/variants', name: 'create_product_variant', methods: ['POST'])]
    public function createProductVariant(Product $product, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $inputDTO = ProductVariantInputDTO::fromArray($data);
        $variant = $this->createProductVariantUseCase->execute($product, $inputDTO);

        return $this->json($variant, JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/product-variants/{id}', name: 'delete_product_variant', methods: ['DELETE'])]
    public function deleteProductVariant(ProductVariant $variant): JsonResponse
    {
        $this->deleteProductVariantUseCase->execute($variant);
        return new JsonResponse(['success' => 'Product variant deleted'], JsonResponse::HTTP_NO_CONTENT);
    }
}
