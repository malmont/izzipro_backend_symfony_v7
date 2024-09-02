<?php

namespace App\Controller\ProductVariantController;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\Color;
use App\Entity\Size;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ProductVariantController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/api/products/{id}/variants', name: 'get_product_variants', methods: ['GET'])]
    public function getProductVariants(Product $product): JsonResponse
    {
        $variants = $product->getVariants();
    
        $variantsArray = [];
        foreach ($variants as $variant) {
            $variantsArray[] = [
                'id' => $variant->getId(),
                'color' => $variant->getColor() ? [
                    'id' => $variant->getColor()->getId(),
                    'name' => $variant->getColor()->getName(),
                    'codeHexa' => $variant->getColor()->getCodeHexa()
                ] : null,
                'size' => $variant->getSize() ? [
                    'id' => $variant->getSize()->getId(),
                    'name' => $variant->getSize()->getName(),
                ] : null,
                'stockQuantity' => $variant->getStockQuantity(),
            ];
        }
    
        return $this->json($variantsArray, JsonResponse::HTTP_OK);
    }
    
    #[Route('/api/products/{id}/variants', name: 'create_product_variant', methods: ['POST'])]
    public function createProductVariant(Product $product, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
    
        // Vérifiez que le stockQuantity est bien fourni
        if (!isset($data['stockQuantity']) || !is_int($data['stockQuantity'])) {
            return new JsonResponse(['error' => 'Stock quantity is required and must be an integer'], JsonResponse::HTTP_BAD_REQUEST);
        }
    
        $variant = new ProductVariant();
        $variant->setProduct($product);
        $variant->setStockQuantity($data['stockQuantity']);
    
        // Assigner la couleur
        if (isset($data['color']['id'])) {
            $color = $this->entityManager->getRepository(Color::class)->find($data['color']['id']);
            if ($color) {
                $variant->setColor($color);
            } else {
                return new JsonResponse(['error' => 'Color not found'], JsonResponse::HTTP_BAD_REQUEST);
            }
        }
    
        // Assigner la taille
        if (isset($data['size']['id'])) {
            $size = $this->entityManager->getRepository(Size::class)->find($data['size']['id']);
            if ($size) {
                $variant->setSize($size);
            } else {
                return new JsonResponse(['error' => 'Size not found'], JsonResponse::HTTP_BAD_REQUEST);
            }
        }
    
        // Enregistrer la variante de produit
        $this->entityManager->persist($variant);
        
        // Création d'un mouvement d'inventaire
        $movementType = $this->entityManager->getRepository(MovementType::class)->find(1); // ID 1 pour 'entrant'
    
        if ($movementType) {
            $inventoryMovement = new InventoryMovements();
            $inventoryMovement->setProductVariant($variant);
            $inventoryMovement->setMovementType($movementType);
            $inventoryMovement->setQuantity($data['stockQuantity']);
            $inventoryMovement->setMovementDate(new \DateTime());
            $inventoryMovement->setStockBeforeMovement(0); // Supposons que le stock précédent était 0 pour une nouvelle création
            $inventoryMovement->setStockAfterMovement($data['stockQuantity']);
    
            $this->entityManager->persist($inventoryMovement);
        } else {
            return new JsonResponse(['error' => 'Movement type not found'], JsonResponse::HTTP_BAD_REQUEST);
        }
    
        $this->entityManager->flush();
    
        return new JsonResponse([
            'success' => 'Product variant created',
            'variant' => [
                'id' => $variant->getId(),
                'color' => [
                    'id' => $variant->getColor() ? $variant->getColor()->getId() : null,
                    'name' => $variant->getColor() ? $variant->getColor()->getName() : null,
                    'codeHexa' => $variant->getColor() ? $variant->getColor()->getCodeHexa() : null,
                ],
                'size' => [
                    'id' => $variant->getSize() ? $variant->getSize()->getId() : null,
                    'name' => $variant->getSize() ? $variant->getSize()->getName() : null,
                ],
                'stockQuantity' => $variant->getStockQuantity(),
            ]
        ], JsonResponse::HTTP_CREATED);
    }
    

    

    #[Route('/api/product-variants/{id}', name: 'delete_product_variant', methods: ['DELETE'])]
        public function deleteProductVariant(ProductVariant $variant): JsonResponse
        {
            $movementType = $this->entityManager->getRepository(MovementType::class)->find(2); // ID 2 pour 'sortant'
            $stockQuantity = $variant->getStockQuantity();

            if ($movementType) {
                $inventoryMovement = new InventoryMovements();
                $inventoryMovement->setProductVariant($variant);
                $inventoryMovement->setMovementType($movementType);
                $inventoryMovement->setQuantity($stockQuantity);
                $inventoryMovement->setMovementDate(new \DateTime());
                $inventoryMovement->setStockBeforeMovement($stockQuantity);
                $inventoryMovement->setStockAfterMovement(0);

                $this->entityManager->persist($inventoryMovement);
            } else {
                return new JsonResponse(['error' => 'Movement type not found'], JsonResponse::HTTP_BAD_REQUEST);
            }

            $this->entityManager->remove($variant);
            $this->entityManager->flush();

            return new JsonResponse(['success' => 'Product variant deleted'], JsonResponse::HTTP_NO_CONTENT);
        }

}
