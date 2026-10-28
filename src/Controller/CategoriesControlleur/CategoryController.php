<?php

namespace App\Controller\CategoriesControlleur;

use App\Dto\ProductOutputCategoryDto;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\UseCase\CategoriesUseCase\GetProductsByCategoryUseCase;
use App\UseCase\CategoriesUseCase\CountProductsByCategoryUseCase;
use App\UseCase\CategoriesUseCase\GetCategoriesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Cache\CacheLocale;
use App\Cache\CacheTags;
use App\Services\MediaUrlResolver;
use App\Services\TenantCacheService;
use App\Dto\CategoryOutputDTO;

class CategoryController extends AbstractController
{
    public function __construct(
        private readonly GetProductsByCategoryUseCase $getProductsByCategoryUseCase,
        private readonly CountProductsByCategoryUseCase $countProductsByCategoryUseCase,
        private readonly GetCategoriesUseCase $getCategoriesUseCase,
        private readonly TenantCacheService $cache,
        private readonly TenantCurrencyProvider $currency,
        private readonly ?MediaUrlResolver $mediaUrlResolver = null
    ) {
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

        $locale = CacheLocale::normalize($request->get('locale'));

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
        $host = $this->mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost())
            ?? $request->getSchemeAndHttpHost();
        $cacheData = $this->cache->get(
            $cacheKey,
            function () use ($categoryIds, $keyword, $page, $pageSize, $barcode, $isWeb, $isPos, $host, $locale) {
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
                $currency = $this->currency->code();
                $dtos = array_map(fn ($product) => new ProductOutputCategoryDto($product, $host, $locale, $currency), $products);

                $total = $this->countProductsByCategoryUseCase->execute($locale, $categoryIds, $keyword, $isWeb, $isPos);

                return [
                    'products' => $dtos,
                    'total'    => $total,
                ];
            },
            300,
            CacheTags::productListTags()
        );

        return new JsonResponse([
            'meta' => [
                'total'    => $cacheData['total'],
                'page'     => $page,
                'pageSize' => $pageSize,
            ],
            'data' => $cacheData['products'],
        ], JsonResponse::HTTP_OK);
    }

    #[Route('/api/category', name: 'get_categories', methods: ['GET'])]
    public function getCategories(Request $request): JsonResponse
    {
        $locale = CacheLocale::normalize($request->get('locale'));
        $cacheKey = 'categories_all_' . $locale;
        $host = $this->mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost())
            ?? $request->getSchemeAndHttpHost();

        $categoriesArray = $this->cache->get(
            $cacheKey,
            function () use ($host, $locale) {
                $categories = $this->getCategoriesUseCase->execute($locale);
                return array_map(
                    fn($category) => (new CategoryOutputDTO($category, $host, $locale))->toArray(),
                    $categories
                );
            },
            3600,
            CacheTags::listTags(CacheTags::CATEGORIES)
        );

        return new JsonResponse($categoriesArray, JsonResponse::HTTP_OK);
    }
}