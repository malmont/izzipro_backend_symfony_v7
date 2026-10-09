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
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;

class ProductVariantController extends AbstractController
{
    private GetProductVariantsUseCase $getProductVariantsUseCase;
    private CreateProductVariantUseCase $createProductVariantUseCase;
    private DeleteProductVariantUseCase $deleteProductVariantUseCase;
    private TenantCacheService $cache;

    public function __construct(
        GetProductVariantsUseCase $getProductVariantsUseCase,
        CreateProductVariantUseCase $createProductVariantUseCase,
        DeleteProductVariantUseCase $deleteProductVariantUseCase,
        TenantCacheService $cache
    ) {
        $this->getProductVariantsUseCase = $getProductVariantsUseCase;
        $this->createProductVariantUseCase = $createProductVariantUseCase;
        $this->deleteProductVariantUseCase = $deleteProductVariantUseCase;
        $this->cache = $cache;
    }

    #[Route('/api/products/{id}/variants', name: 'get_product_variants', methods: ['GET'])]
    public function getProductVariants(Product $product): JsonResponse
    {
        $cacheKey = 'product_variants_' . $product->getId();
        $variants = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($product) {
                $item->expiresAfter(300); 
                $item->tag(['product_variants']);
                return $this->getProductVariantsUseCase->execute($product);
            },
        );

        return $this->json($variants, JsonResponse::HTTP_OK);
    }

    // Création et suppression d'une variante : BoutiqueCatalogController (09/10/2026 : règles, journal, ROLE_ADMIN ;
    // la suppression était ouverte à tout client connecté)
}
