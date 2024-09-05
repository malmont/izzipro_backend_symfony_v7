<?php
namespace App\Controller\CategoriesControlleur;


use App\Dto\CategoryOutputDTO;
use App\Dto\ProductDetailedOutputDTO;
use App\UseCase\CategoriesUseCase\GetCategoriesUseCase;
use App\UseCase\CategoriesUseCase\GetProductsByCategoryUseCase;
use App\UseCase\CategoriesUseCase\CountProductsByCategoryUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class CategoryController extends AbstractController
{
    private GetProductsByCategoryUseCase $getProductsByCategoryUseCase;
    private CountProductsByCategoryUseCase $countProductsByCategoryUseCase;

    public function __construct(
        GetProductsByCategoryUseCase $getProductsByCategoryUseCase,
        CountProductsByCategoryUseCase $countProductsByCategoryUseCase
    ) {
        $this->getProductsByCategoryUseCase = $getProductsByCategoryUseCase;
        $this->countProductsByCategoryUseCase = $countProductsByCategoryUseCase;
    }

    #[Route('/api/products/by-category', name: 'get_products_by_category', methods: ['GET'])]
    public function getProductsByCategory(Request $request): JsonResponse
    {
        $categoryIds = $request->query->get('categories');
        $page = $request->query->getInt('page', 1);
        $pageSize = $request->query->getInt('pageSize', 10);

        if ($categoryIds) {
            $categoryIds = json_decode($categoryIds);
        }

        $products = $this->getProductsByCategoryUseCase->execute($categoryIds, $page, $pageSize);
        $totalProducts = $this->countProductsByCategoryUseCase->execute($categoryIds);

        $host = $request->getSchemeAndHttpHost() . '/jeesign';
        $productsDTO = array_map(fn($product) => new ProductDetailedOutputDTO($product, $host), $products);

        return new JsonResponse([
            'meta' => [
                'total' => $totalProducts,
                'page' => $page,
                'pageSize' => $pageSize,
            ],
            'data' => $productsDTO,
        ], JsonResponse::HTTP_OK);
    }
}