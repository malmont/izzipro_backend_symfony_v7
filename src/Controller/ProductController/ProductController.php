<?php

namespace App\Controller\ProductController;

use App\Entity\Commande;
use App\Entity\Color;
use App\Entity\Size;
use App\Entity\Style;
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
}
