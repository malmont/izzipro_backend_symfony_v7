<?php

namespace App\Controller\ProductController;

use App\Entity\Commande;
use App\Entity\Product;
use App\Dto\ProductOutputDTO;
use App\UseCase\ProductUseCase\GetProductsByCommandeUseCase;
use App\UseCase\ProductUseCase\CreateProductByCommandeUseCase;
use App\UseCase\ProductUseCase\GetLandingPageProductsUseCase;
use App\UseCase\ProductUseCase\GetProductByIdUseCase; 
use App\UseCase\ProductUseCase\DeleteProductUseCase;
use App\UseCase\ProductUseCase\GetAllProductsUseCase;
use App\UseCase\ProductUseCase\GetProductsByOfferUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;

class ProductController extends AbstractController
{
    private GetProductsByCommandeUseCase $getProductsByCommandeUseCase;
    private CreateProductByCommandeUseCase $createProductByCommandeUseCase;
    private GetLandingPageProductsUseCase $getLandingPageProductsUseCase;
    private DeleteProductUseCase $deleteProductUseCase;
    private TenantEntityManagerProvider $emProvider;
    private GetAllProductsUseCase $getAllProductsUseCase;
    private GetProductsByOfferUseCase $getProductsByOfferUseCase;
    private TenantCacheService $cache;
    private GetProductByIdUseCase $getProductByIdUseCase; 

    public function __construct(
        GetProductsByCommandeUseCase $getProductsByCommandeUseCase,
        CreateProductByCommandeUseCase $createProductByCommandeUseCase,
        GetLandingPageProductsUseCase $getLandingPageProductsUseCase,
        DeleteProductUseCase $deleteProductUseCase,
        TenantEntityManagerProvider $emProvider,
        GetAllProductsUseCase $getAllProductsUseCase,
        GetProductsByOfferUseCase $getProductsByOfferUseCase,
        TenantCacheService $cache,
        GetProductByIdUseCase $getProductByIdUseCase
    ) {
        $this->getProductsByCommandeUseCase = $getProductsByCommandeUseCase;
        $this->createProductByCommandeUseCase = $createProductByCommandeUseCase;
        $this->getLandingPageProductsUseCase = $getLandingPageProductsUseCase;
        $this->deleteProductUseCase = $deleteProductUseCase;
        $this->emProvider = $emProvider;
        $this->getAllProductsUseCase = $getAllProductsUseCase;
        $this->getProductsByOfferUseCase = $getProductsByOfferUseCase;
        $this->cache = $cache;
        $this->getProductByIdUseCase = $getProductByIdUseCase;
    }

    #[Route('/api/commandes/{id}/products', name: 'get_products_by_commande', methods: ['GET'])]
    public function getProductsByCommande(Commande $commande, Request $request): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost();
        $cacheKey = 'products_by_commande_' . $commande->getId();

        $products = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($commande, $host) {
                $item->expiresAfter(300);
                $item->tag(['products_command']);
                return $this->getProductsByCommandeUseCase->execute($commande, $host);
            },
            /* ttl */ 300,
            /* extraTags */ ['products_command']
        );

        return $this->json($products, JsonResponse::HTTP_OK);
    }

    #[Route('/api/commandes/{id}/products', name: 'create_product_by_commande', methods: ['POST'])]
    public function createProductByCommande(Commande $commande, Request $request): JsonResponse
    {
        try {

            $uploadDir = $this->getParameter('kernel.project_dir') . '/public/assets/uploads/products/';
            $product = $this->createProductByCommandeUseCase->execute($commande, $request, $uploadDir);

            $host = $request->getSchemeAndHttpHost();
            $productDTO = new ProductOutputDTO($product, $host);

            return $this->json($productDTO, JsonResponse::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/products/{id}', name: 'delete_product', methods: ['DELETE'])]
    public function deleteProduct(int $id): JsonResponse
    {
        try {
            $this->deleteProductUseCase->execute($id);
            return new JsonResponse(['success' => 'Product deleted'], JsonResponse::HTTP_OK);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/products', name: 'get_all_products', methods: ['GET'])]
    public function getAllProducts(Request $request): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost();
        $cacheKey = 'all_products';

        $products = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($host) {
                $item->expiresAfter(300);
                $item->tag(['products_all']);
                return $this->getAllProductsUseCase->execute($host);
            },
            /* ttl */ 300,
            /* extraTags */ ['products_all']
        );

        return $this->json($products, JsonResponse::HTTP_OK);
    }

    #[Route('/api/products/{offer}', name: 'get_products_by_offer', methods: ['GET'])]
    public function getProductsByOffer(string $offer, Request $request): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost();
        $cacheKey = 'products_by_offer_' . $offer;

        $products = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($offer, $host) {
                $item->expiresAfter(300);
                $item->tag(['products_by_offer']);
                return $this->getProductsByOfferUseCase->execute($offer, $host);
            },
            /* ttl */ 300,
            /* extraTags */ ['products_by_offer']
        );

        return $this->json($products, JsonResponse::HTTP_OK);
    }

    #[Route('/api/landingpage', name: 'get_landing_page_products', methods: ['GET'])]
    public function getLandingPageProducts(Request $request): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost();
        $products = $this->getLandingPageProductsUseCase->execute($host);

        return $this->json($products, JsonResponse::HTTP_OK);
    }

    #[Route('/api/productsid/{id}', name: 'get_product_by_id', methods: ['GET'])]
    public function getProductById(int $id, Request $request): JsonResponse
    {
        try {
            $host = $request->getSchemeAndHttpHost();
            $product = $this->getProductByIdUseCase->execute($id, $host);
            return $this->json($product, JsonResponse::HTTP_OK);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], JsonResponse::HTTP_NOT_FOUND);
        }
    }
}
