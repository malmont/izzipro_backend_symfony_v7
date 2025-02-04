<?php
namespace App\Controller\CategoriesControlleur;

use App\Entity\Categories;
use App\Entity\Product;
use App\Dto\CategoryOutputDTO;
use App\Dto\ProductDetailedOutputDTO;
use App\UseCase\CategoriesUseCase\GetCategoriesUseCase;
use App\UseCase\CategoriesUseCase\GetProductsByCategoryUseCase;
use App\UseCase\CategoriesUseCase\CountProductsByCategoryUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;

class CategoryController extends AbstractController
{
    private GetProductsByCategoryUseCase $getProductsByCategoryUseCase;
    private CountProductsByCategoryUseCase $countProductsByCategoryUseCase;
    private $entityManager;
    public function __construct(
        GetProductsByCategoryUseCase $getProductsByCategoryUseCase,
        CountProductsByCategoryUseCase $countProductsByCategoryUseCase,
        EntityManagerInterface $entityManager
    ) {
        $this->getProductsByCategoryUseCase = $getProductsByCategoryUseCase;
        $this->countProductsByCategoryUseCase = $countProductsByCategoryUseCase;
        $this->entityManager = $entityManager;
    }

   #[Route('/api/products/by-category', name: 'get_products_by_category', methods: ['GET'])]
    public function getProductsByCategory(Request $request): JsonResponse
    {
        $categoryIds = $request->query->get('categories');
        $keyword = $request->query->get('keyword'); 
        $page = $request->query->getInt('page', 1);
        $pageSize = $request->query->getInt('pageSize', 12);
        $barcode = $request->query->get('barcode');

        if ($categoryIds) {
            $categoryIds = json_decode($categoryIds);
        }

        $products = $this->getProductsByCategoryUseCase->execute($categoryIds, $keyword, $page, $pageSize, $barcode);
        $totalProducts = $this->countProductsByCategoryUseCase->execute($categoryIds);

        $host = $request->getSchemeAndHttpHost() ;
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


    #[Route('/api/category', name: 'get_categories', methods: ['GET'])]
    public function getCategories(Request $request): JsonResponse
        {
            $categories = $this->entityManager->getRepository(Categories::class)->findAll();

            // Obtenir l'URL de base de l'hôte
            $host = $request->getSchemeAndHttpHost();

            // Manuellement composer la réponse JSON sans les produits associés
            $categoriesArray = [];
            foreach ($categories as $category) {
                $categoriesArray[] = [
                    'id' => $category->getId(),
                    'name' => $category->getName(),
                    'description' => $category->getDescription(),
                    'image' => $category->getImage() ? $host . '/assets/uploads/categories/' . $category->getImage() : null,
                ];
            }

            return new JsonResponse($categoriesArray, JsonResponse::HTTP_OK);
        }
}