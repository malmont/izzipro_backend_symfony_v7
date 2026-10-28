<?php

namespace App\Controller\ProductController;

use App\Entity\Commande;
use App\Entity\Product;
use App\Dto\ProductOutputDTO;
use App\UseCase\ProductUseCase\GetProductsByCommandeUseCase;
use App\UseCase\ProductUseCase\CreateProductByCommandeUseCase;
use App\UseCase\ProductUseCase\GetLandingPageProductsUseCase;
use App\UseCase\ProductUseCase\GetProductByIdUseCase; 
use App\UseCase\ProductUseCase\GetProductBySlugUseCase;
use App\UseCase\ProductUseCase\DeleteProductUseCase;
use App\UseCase\ProductUseCase\GetAllProductsUseCase;
use App\UseCase\ProductUseCase\GetProductsByOfferUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Cache\CacheLocale;
use App\Cache\CacheTags;
use App\Services\MediaUrlResolver;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantCacheService;

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
    private GetProductBySlugUseCase $getProductBySlugUseCase;
    private ?MediaUrlResolver $mediaUrlResolver;

    public function __construct(
        GetProductsByCommandeUseCase $getProductsByCommandeUseCase,
        CreateProductByCommandeUseCase $createProductByCommandeUseCase,
        GetLandingPageProductsUseCase $getLandingPageProductsUseCase,
        DeleteProductUseCase $deleteProductUseCase,
        TenantEntityManagerProvider $emProvider,
        GetAllProductsUseCase $getAllProductsUseCase,
        GetProductsByOfferUseCase $getProductsByOfferUseCase,
        TenantCacheService $cache,
        GetProductByIdUseCase $getProductByIdUseCase,
        GetProductBySlugUseCase $getProductBySlugUseCase,
        ?MediaUrlResolver $mediaUrlResolver = null
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
        $this->getProductBySlugUseCase = $getProductBySlugUseCase;
        $this->mediaUrlResolver = $mediaUrlResolver;
    }

    private function resolveHost(Request $request): string
    {
        return $this->mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost())
            ?? $request->getSchemeAndHttpHost();
    }

    #[Route('/api/commandes/{id}/products', name: 'get_products_by_commande', methods: ['GET'])]
    public function getProductsByCommande(Commande $commande, Request $request): JsonResponse
    {
        $host = $this->resolveHost($request);
        $cacheKey = 'products_by_commande_' . $commande->getId();

        $products = $this->cache->get(
            $cacheKey,
            fn () => $this->getProductsByCommandeUseCase->execute($commande, $host),
            300,
            [...CacheTags::productListTags(), ...CacheTags::itemTags(CacheTags::COMMANDES, $commande->getId())]
        );

        return $this->json($products, JsonResponse::HTTP_OK);
    }

    #[Route('/api/commandes/{id}/products', name: 'create_product_by_commande', methods: ['POST'])]
    public function createProductByCommande(Commande $commande, Request $request): JsonResponse
    {
        try {

            $uploadDir = $this->getParameter('kernel.project_dir') . '/public/assets/uploads/products/';
            $product = $this->createProductByCommandeUseCase->execute($commande, $request, $uploadDir);

            $host = $this->resolveHost($request);
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
        $host = $this->resolveHost($request);
        $locale = CacheLocale::normalize($request->query->get('locale'));
        $cacheKey = 'all_products_' . $locale;

        $products = $this->cache->get(
            $cacheKey,
            fn () => $this->getAllProductsUseCase->execute($host, $locale),
            300,
            CacheTags::productListTags()
        );

        return $this->json($products, JsonResponse::HTTP_OK);
    }

    #[Route(
        '/api/products/{offer}', 
        name: 'get_products_by_offer', 
        methods: ['GET'], 
        priority: 10,
        requirements: ['offer' => 'bestsellers|newarrivals|specialoffers|isfeatured|isAccessory']
    )]
    public function getProductsByOffer(string $offer, Request $request): JsonResponse
    {
        $host = $this->resolveHost($request);
        $locale = CacheLocale::normalize($request->query->get('locale'));
        $cacheKey = 'products_by_offer_' . $offer . '_' . $locale;

        $products = $this->cache->get(
            $cacheKey,
            fn () => $this->getProductsByOfferUseCase->execute($offer, $host, $locale),
            300,
            CacheTags::productListTags()
        );

        return $this->json($products, JsonResponse::HTTP_OK);
    }

   #[Route('/api/landingpage', name: 'get_landing_page_products', methods: ['GET'])]
    public function getLandingPageProducts(Request $request): JsonResponse
    {
        $host = $this->resolveHost($request);
        $locale = $request->query->get('locale', 'fr');
        $products = $this->getLandingPageProductsUseCase->execute($host, $locale); 

        return $this->json($products, JsonResponse::HTTP_OK);
    }

    /**
     * Fiche produit en cache 5 min depuis le 28/10/2026 (lue à chaque vue de la fiche), vidée par l'écriture du produit
     * ou d'un de ses enfants (variante, stock, image, traduction) et par celle d'un référentiel ; une fiche introuvable
     * (exception) n'est pas mise en cache.
     */
    #[Route('/api/productsid/{id}', name: 'get_product_by_id', methods: ['GET'])]
    public function getProductById(int $id, Request $request): JsonResponse
    {
        try {
            $host = $this->resolveHost($request);
            $locale = CacheLocale::normalize($request->query->get('locale'));
            $product = $this->cache->get(
                'product_detail_' . $id . '_' . $locale,
                fn () => $this->getProductByIdUseCase->execute($id, $host, $locale),
                300,
                CacheTags::productItemTags($id)
            );
            return $this->json($product, JsonResponse::HTTP_OK);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], JsonResponse::HTTP_NOT_FOUND);
        }
    }

    /**
     * Fiche d'un produit par identifiant, même contrat que by-slug et productsid. Passe avant la route d'API Platform
     * /api/products/{id}, dont la sérialisation ne répondait jamais (boutique réglable, 09/10/2026).
     */
    #[Route('/api/products/{id}', name: 'get_product_detail', methods: ['GET'], priority: 20, requirements: ['id' => '\d+'])]
    public function getProductDetail(int $id, Request $request): JsonResponse
    {
        return $this->getProductById($id, $request);
    }

    #[Route('/api/products/by-slug/{slug}', name: 'get_product_by_slug', methods: ['GET'])]
    public function getProductBySlug(string $slug, Request $request): JsonResponse
    {
        try {
            $host = $this->resolveHost($request);
            $locale = CacheLocale::normalize($request->query->get('locale'));
            // même entrée de cache que la fiche par identifiant : une fiche est vidée par l'écriture de son produit
            $id = $this->emProvider->getEntityManager()->getRepository(Product::class)->findBySlugAndLocale($slug, $locale)?->getId();
            if ($id === null) {
                return $this->json(['error' => 'Produit introuvable'], JsonResponse::HTTP_NOT_FOUND);
            }
            $product = $this->cache->get(
                'product_detail_' . $id . '_' . $locale,
                fn () => $this->getProductBySlugUseCase->execute($slug, $host, $locale),
                300,
                CacheTags::productItemTags($id)
            );
            return $this->json($product, JsonResponse::HTTP_OK);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], JsonResponse::HTTP_NOT_FOUND);
        }
    }
}
