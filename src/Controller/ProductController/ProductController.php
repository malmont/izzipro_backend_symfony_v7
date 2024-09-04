<?php

namespace App\Controller\ProductController;

use App\Entity\Commande;
use App\Dto\ProductOutputDTO;
use App\UseCase\ProductUseCase\GetProductsByCommandeUseCase;
use App\UseCase\ProductUseCase\CreateProductByCommandeUseCase;
use App\UseCase\ProductUseCase\DeleteProductUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;


class ProductController extends AbstractController
{
    private GetProductsByCommandeUseCase $getProductsByCommandeUseCase;
    private CreateProductByCommandeUseCase $createProductByCommandeUseCase;
    private DeleteProductUseCase $deleteProductUseCase;

    public function __construct(
        GetProductsByCommandeUseCase $getProductsByCommandeUseCase,
        CreateProductByCommandeUseCase $createProductByCommandeUseCase,
        DeleteProductUseCase $deleteProductUseCase
    ) {
        $this->getProductsByCommandeUseCase = $getProductsByCommandeUseCase;
        $this->createProductByCommandeUseCase = $createProductByCommandeUseCase;
        $this->deleteProductUseCase = $deleteProductUseCase;
    }

    #[Route('/api/commandes/{id}/products', name: 'get_products_by_commande', methods: ['GET'])]
    public function getProductsByCommande(Commande $commande, Request $request): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost() . '/jeesign';
        $products = $this->getProductsByCommandeUseCase->execute($commande, $host);

        return $this->json($products, JsonResponse::HTTP_OK);
    }

    #[Route('/api/commandes/{id}/products', name: 'create_product_by_commande', methods: ['POST'])]
    public function createProductByCommande(Commande $commande, Request $request): JsonResponse
    {
        try {
            // Récupérer le répertoire d'upload depuis les paramètres de l'application
            $uploadDir = $this->getParameter('kernel.project_dir') . '/public/assets/uploads/products/';
            
            // Exécuter le Use Case pour créer le produit
            $product = $this->createProductByCommandeUseCase->execute($commande, $request, $uploadDir);

            // Convertir le produit en DTO de sortie
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
}