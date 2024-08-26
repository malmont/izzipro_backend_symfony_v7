<?php
namespace App\Controller\CategoriesControlleur;

use App\Entity\Categories;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

class CategoryController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/api/category', name: 'get_categories', methods: ['GET'])]
    public function getCategories(Request $request): JsonResponse
    {
        $categories = $this->entityManager->getRepository(Categories::class)->findAll();
    
        // Obtenir l'URL de base de l'hôte
        $host = $request->getSchemeAndHttpHost() . '/jeesign';
    
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
    

    #[Route('/api/products/by-category', name: 'get_products_by_category', methods: ['GET'])]
    public function getProductsByCategory(Request $request): JsonResponse
    {
        // Récupérer les paramètres
        $categoryIds = $request->query->get('categories');
        $page = $request->query->getInt('page', 1);
        $pageSize = $request->query->getInt('pageSize', 10);
    
        // Convertir les catégories en tableau d'IDs
        if ($categoryIds) {
            $categoryIds = json_decode($categoryIds);
        }
    
        // Construire la requête pour récupérer les produits avec pagination
        $queryBuilder = $this->entityManager->getRepository(Product::class)->createQueryBuilder('p');
    
        if ($categoryIds) {
            $queryBuilder->join('p.category', 'c')
                        ->andWhere('c.id IN (:categoryIds)')
                        ->setParameter('categoryIds', $categoryIds);
        }
    
        // Appliquer la pagination
        $queryBuilder->setFirstResult(($page - 1) * $pageSize)
                    ->setMaxResults($pageSize);
    
        $products = $queryBuilder->getQuery()->getResult();
    
        // Compter le nombre total de produits pour la pagination
        $totalProducts = $queryBuilder->select('COUNT(p.id)')
                                    ->setFirstResult(0)
                                    ->setMaxResults(null)
                                    ->getQuery()
                                    ->getSingleScalarResult();
    
        // Obtenir l'URL de base de l'hôte
        $host = $request->getSchemeAndHttpHost() . '/jeesign';
    
        // Composer la réponse JSON
        $productsArray = [];
        foreach ($products as $product) {
            $productsArray[] = [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'description' => $product->getDescription(),
                'moreinformations' => $product->getMoreinformations(),
                'price' => $product->getPrice(),
                'isbestseller' => $product->isIsbestseller(),
                'isnewarrival' => $product->isIsnewarrival(),
                'isfeatured' => $product->isIsfeatured(),
                'isspecialoffer' => $product->isIsspecialoffer(),
                'image' => $product->getImage() ? $host . '/assets/uploads/products/' . $product->getImage() : null,
                'quantity' => $product->getQuantity(),
                'createdAt' => $product->getCreatedAt()->format('Y-m-d H:i:s'),
                'tags' => $product->getTags(),
                'slug' => $product->getSlug(),
                'purchasePrice' => $product->getPurchasePrice(),
                'coefficientMultiplier' => $product->getCoefficientMultiplier(),
                'barcode' => $product->getBarcode(),
                'style' => $product->getStyle() ? [
                    'id' => $product->getStyle()->getId(),
                    'name' => $product->getStyle()->getName(),
                ] : null,
                'variants' => array_map(function ($variant) {
                    return [
                        'id' => $variant->getId(),
                        'color' => [
                            'id' => $variant->getColor()->getId(),
                            'name' => $variant->getColor()->getName(),
                            'codeHexa' => $variant->getColor()->getCodeHexa(),
                        ],
                        'size' => [
                            'id' => $variant->getSize()->getId(),
                            'name' => $variant->getSize()->getName(),
                        ],
                        'stockQuantity' => $variant->getStockQuantity(),
                    ];
                }, $product->getVariants()->toArray()),
                'category' => array_map(function ($category) {
                    return [
                        'id' => $category->getId(),
                        'name' => $category->getName(),
                        'description' => $category->getDescription(),
                        'image' => $category->getImage(),
                    ];
                }, $product->getCategory()->toArray()),
            ];
        }
    
        return new JsonResponse([
            'meta' => [
                'total' => $totalProducts,
                'page' => $page,
                'pageSize' => $pageSize,
            ],
            'data' => $productsArray,
        ], JsonResponse::HTTP_OK);
    }
    

    }
