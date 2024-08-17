<?php

namespace App\Controller\ProductController;

use App\Entity\Commande;
use App\Entity\Color;
use App\Entity\Size;
use App\Entity\Style;
use App\Entity\Product;
use App\Entity\Categories;
use Cocur\Slugify\Slugify;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\HttpFoundation\RequestStack;

class ProductController extends AbstractController
{
    private $entityManager;
    private $requestStack;

    public function __construct(EntityManagerInterface $entityManager, RequestStack $requestStack)
    {
        $this->entityManager = $entityManager;
        $this->requestStack = $requestStack; 
    }

    #[Route('/api/commandes/{id}/products', name: 'get_products_by_commande', methods: ['GET'])]
    public function getProductsByCommande(Commande $commande): JsonResponse
    {
        // Récupérer les produits associés à la commande
        $products = $commande->getProducts();

           // Récupérer le domaine de l'application
           $request = $this->requestStack->getCurrentRequest();
           $host = $request->getSchemeAndHttpHost() . '/jeesign';

        // Convertir les objets Product en tableau pour une meilleure lisibilité
        $productsArray = [];
        foreach ($products as $product) {
            $productsArray[] = [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'description' => $product->getDescription(),
                'moreinformations' => $product->getMoreinformations(),
                'price' => $product->getPrice(),
                'purchasePrice' => $product->getPurchasePrice(),
                'coefficientMultiplier' => $product->getCoefficientMultiplier(),
                'barcode' => $product->getBarcode(),
                'isbestseller' => $product->isIsbestseller(),
                'isnewarrival' => $product->isIsnewarrival(),
                'isfeatured' => $product->isIsfeatured(),
                'isspecialoffer' => $product->isIsspecialoffer(),
                'image' => $product->getImage() ? $host . '/assets/uploads/products/' . $product->getImage() : null,
                'quantity' => $product->getQuantity(),
                'createdAt' => $product->getCreatedAt()->format('Y-m-d H:i:s'),
                'tags' => $product->getTags(),
                'slug' => $product->getSlug(),
                // 'style' => $product->getStyle() ? $product->getStyle()->getName() : null,
                'style' => $this->getStyleData($product->getStyle()),
                'variants' => $this->getVariantsData($product->getVariants()), // Si vous voulez ajouter des variantes
                'category' => $this->getCategoryNames($product->getCategory()), // Récupère le nom de la catégorie
            ];
        }

        return $this->json($productsArray, 200);
    }

    private function getVariantsData(Collection $variants): array
    {
        $variantsArray = [];
        foreach ($variants as $variant) {
            $variantsArray[] = [
                'id' => $variant->getId(),
                'color' => $this->getColorData($variant->getColor()), // Notez l'appel à getColorData()
                'size' => $this->getSizeData($variant->getSize()),
                // 'size' => $variant->getSize() ? $variant->getSize()->getName() : null,
                'stockQuantity' => $variant->getStockQuantity(),
            ];
        }
        return $variantsArray;
    }
    
    private function getColorData(?Color $color): ?array
    {
        if ($color === null) {
            return null;
        }
    
        return [
            'id' => $color->getId(),
            'name' => $color->getName(),
            'codeHexa' => $color->getCodeHexa(),
        ];
    }

    private function getSizeData(?Size $size): ?array
    {
        if ($size === null) {
            return null;
        }
    
        return [
            'id' => $size->getId(),
            'name' => $size->getName(),
            
        ];
    }

    private function getStyleData(?Style $style): ?array
    {
        if ($style === null) {
            return null;
        }
    
        return [
            'id' => $style->getId(),
            'name' => $style->getName(),
            
        ];
    }
    private function getCategoryNames(Collection $categories): array
    {
        $categoryNames = [];
        foreach ($categories as $category) {
            $categoryNames[] = [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'description' => $category->getDescription(),
                'image' => $category->getImage(),
         ];
        }
        return  $categoryNames; // Retourne les noms des catégories sous forme de chaîne
    }
   
    #[Route('/api/commandes/{id}/products', name: 'create_product_by_commande', methods: ['POST'])]
    public function createProductByCommande(Commande $commande, Request $request): JsonResponse
    {
        // Création du produit et définition de ses propriétés
        $product = new Product();
        $product->setName($request->get('name'));
        $product->setDescription($request->get('description'));
        $product->setPurchasePrice($request->get('purchasePrice'));
        $product->setCoefficientMultiplier($request->get('coefficientMultiplier'));
        $product->setCommande($commande);
    
        // Ajout du style au produit
        $styleId = $request->get('style_id');
        if ($styleId) {
            $style = $this->entityManager->getRepository(Style::class)->find($styleId);
            if ($style) {
                $product->setStyle($style);
            } else {
                return new JsonResponse(['error' => 'Style not found'], JsonResponse::HTTP_BAD_REQUEST);
            }
        }
    
        // Ajout des catégories au produit
        $categoryIds = $request->get('category_ids', []);
        if (is_string($categoryIds)) {
            $categoryIds = explode(',', $categoryIds);
        }
    
        if (!empty($categoryIds)) {
            foreach ($categoryIds as $categoryId) {
                $category = $this->entityManager->getRepository(Categories::class)->find($categoryId);
                if ($category) {
                    $product->addCategory($category);
                } else {
                    return new JsonResponse(['error' => 'Category not found for ID: ' . $categoryId], JsonResponse::HTTP_BAD_REQUEST);
                }
            }
        }
    
        // Générer le slug à partir du name
        $slugify = new Slugify();
        $slug = $slugify->slugify($request->get('name'));
        $product->setSlug($slug);
    
        // Gestion de l'upload d'image
        $imageFile = $request->files->get('image');
        if ($imageFile) {
            $newFilename = uniqid() . '.' . $imageFile->guessExtension();
    
            try {
                $imageFile->move(
                    $this->getParameter('kernel.project_dir') . '/public/assets/uploads/products/',
                    $newFilename
                );
            } catch (FileException $e) {
                return new JsonResponse(['error' => 'Could not upload file'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
            }
    
            $product->setImage($newFilename);
        }
    
        // Persist and flush the product entity
        $this->entityManager->persist($product);
        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to save product: ' . $e->getMessage()], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    
        // Construction manuelle de la réponse
        $productData = [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'description' => $product->getDescription(),
            'moreinformations' => $product->getMoreinformations(),
            'price' => $product->getPrice(),
            'isbestseller' => $product->isIsbestseller(),
            'isnewarrival' => $product->isIsnewarrival(),
            'isfeatured' => $product->isIsfeatured(),
            'isspecialoffer' => $product->isIsspecialoffer(),
            'image' => $product->getImage(),
            'quantity' => $product->getQuantity(),
            'createdAt' => $product->getCreatedAt()->format('Y-m-d H:i:s'),
            'tags' => $product->getTags(),
            'slug' => $product->getSlug(),
            'purchasePrice' => $product->getPurchasePrice(),
            'coefficientMultiplier' => $product->getCoefficientMultiplier(),
            'barcode' => $product->getBarcode(),
            'style' => $product->getStyle() ? $product->getStyle()->getName() : null,
            'category' => $this->getCategoryNames($product->getCategory()),
            'commande' => $product->getCommande() ? $product->getCommande()->getName() : null,
        ];
    
        return $this->json($productData, JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/products/{id}', name: 'delete_product', methods: ['DELETE'])]
    public function deleteProduct(int $id): JsonResponse
    {
        $product = $this->entityManager->getRepository(Product::class)->find($id);

        if (!$product) {
            return new JsonResponse(['error' => 'Product not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $this->entityManager->remove($product);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to delete product: ' . $e->getMessage()], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['success' => 'Product deleted'], JsonResponse::HTTP_OK);
    }
    
}
