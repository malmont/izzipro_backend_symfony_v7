<?php

namespace App\Controller\CategoriesControlleur;

use App\Entity\Categories;
use App\Dto\ProductOutputCategoryDto;
use App\UseCase\CategoriesUseCase\GetProductsByCategoryUseCase;
use App\UseCase\CategoriesUseCase\CountProductsByCategoryUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;
use App\Dto\CategoryOutputDTO;

class CategoryController extends AbstractController
{
    private GetProductsByCategoryUseCase $getProductsByCategoryUseCase;
    private CountProductsByCategoryUseCase $countProductsByCategoryUseCase;
    private TenantEntityManagerProvider $emProvider;
    private TenantCacheService $cache;

    public function __construct(
        GetProductsByCategoryUseCase $getProductsByCategoryUseCase,
        CountProductsByCategoryUseCase $countProductsByCategoryUseCase,
        TenantEntityManagerProvider $emProvider,
        TenantCacheService $cache
    ) {
        $this->getProductsByCategoryUseCase = $getProductsByCategoryUseCase;
        $this->countProductsByCategoryUseCase = $countProductsByCategoryUseCase;
        $this->emProvider = $emProvider;
        $this->cache = $cache;
    }

    #[Route('/api/products/by-category', name: 'get_products_by_category', methods: ['GET'], priority: 10)]
    public function getProductsByCategory(Request $request): JsonResponse
    {
        $categoryIds = $request->query->get('categories');
        $keyword = $request->query->get('keyword');
        $page = $request->query->getInt('page', 1);
        $pageSize = $request->query->getInt('pageSize', 12);
        $barcode = $request->query->get('barcode');
        $isWeb = $request->query->has('isWeb')
            ? filter_var($request->query->get('isWeb'), FILTER_VALIDATE_BOOLEAN)
            : null;
        $isPos = $request->query->has('isPos')
            ? filter_var($request->query->get('isPos'), FILTER_VALIDATE_BOOLEAN)
            : null;

        if ($categoryIds) {
            $categoryIds = json_decode($categoryIds, true);
        }

        $locale = $request->get('locale', 'fr');

        $cacheKey = 'products_by_category_' . md5(json_encode([
            'categories' => $categoryIds,
            'keyword'    => $keyword,
            'page'       => $page,
            'pageSize'   => $pageSize,
            'barcode'    => $barcode,
            'isWeb'      => $isWeb,
            'isPos'      => $isPos,
            'locale'     => $locale,
        ]));
        $host = $request->getSchemeAndHttpHost();
        $productsDTOArray = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($categoryIds, $keyword, $page, $pageSize, $barcode, $isWeb, $isPos, $host, $locale) {
                $item->expiresAfter(300); 
                $item->tag(['products_by_category', 'locale_' . $locale]); 
                $products = $this->getProductsByCategoryUseCase->execute(
                    $locale,
                    $categoryIds,
                    $keyword,
                    $page,
                    $pageSize,
                    $barcode,
                    $isWeb,
                    $isPos,
                    
                );
                return array_map(function ($product) use ($host, $locale) {
                    $dto = new ProductOutputCategoryDto($product, $host, $locale);
                    return $dto;
                }, $products);
            },
        );

        $totalProducts = $this->countProductsByCategoryUseCase->execute($locale, $categoryIds, $keyword);
        return new JsonResponse([
            'meta' => [
                'total' => $totalProducts,
                'page' => $page,
                'pageSize' => $pageSize,
            ],
            'data' => $productsDTOArray,
        ], JsonResponse::HTTP_OK);
    }

    #[Route('/api/category', name: 'get_categories', methods: ['GET'])]
    public function getCategories(Request $request): JsonResponse
    {
        $locale = $request->get('locale', 'fr');
        $cacheKey = 'categories_all_' . $locale;
        $host = $request->getSchemeAndHttpHost();

        $categoriesArray = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($host, $locale) {
                $item->expiresAfter(3600);
                $item->tag(['categories_all', 'locale_' . $locale]);

                $em = $this->emProvider->getEntityManager();
                $categories = $em->getRepository(Categories::class)->findAll();
                return array_map(
                    fn($category) => (new CategoryOutputDTO($category, $host, $locale))->toArray(),
                    $categories
                );
            },
        );

        return new JsonResponse($categoriesArray, JsonResponse::HTTP_OK);
    }
}