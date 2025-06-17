<?php

namespace App\Services\ProductService;

use App\Entity\Product;
use App\Entity\Commande;
use App\Entity\Categories;
use App\Entity\Style;
use App\Dto\ProductInputDTO;
use App\Dto\ProductDetailedOutputDTO;
use App\Services\EntityRetrieverService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Cocur\Slugify\Slugify;

class ProductService
{
    private TenantEntityManagerProvider $emProvider;
    private EntityRetrieverService $entityRetrieverService;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        EntityRetrieverService $entityRetrieverService
    ) {
        $this->emProvider = $emProvider;
        $this->entityRetrieverService = $entityRetrieverService;
    }

    public function getProductsByCommande(Commande $commande, string $host): array
    {
        $products = $commande->getProducts();
        return array_map(fn($product) => new ProductDetailedOutputDTO($product, $host), $products->toArray());
    }

    public function createProductByCommande(Commande $commande, ProductInputDTO $inputDTO, Request $request, string $uploadDir): Product
    {
        $em = $this->emProvider->getEntityManager();

        $product = new Product();
        $product->setName($inputDTO->name);
        $product->setDescription($inputDTO->description);
        $product->setPurchasePrice($inputDTO->purchasePrice);
        $product->setCoefficientMultiplier($inputDTO->coefficientMultiplier);
        $product->setCommande($commande);

        if ($inputDTO->styleId) {
            $style = $this->entityRetrieverService->findOrFail(Style::class, $inputDTO->styleId, 'Style not found');
            $product->setStyle($style);
        }

        foreach ($inputDTO->categoryIds as $categoryId) {
            $category = $this->entityRetrieverService->findOrFail(Categories::class, $categoryId, "Category not found for ID: $categoryId");
            $product->addCategory($category);
        }

        $slugify = new Slugify();
        $product->setSlug($slugify->slugify($inputDTO->name));

        $imageFile = $request->files->get('image');
        if ($imageFile) {
            $newFilename = uniqid() . '.' . $imageFile->guessExtension();
            $imageFile->move($uploadDir, $newFilename);
            $product->setImage($newFilename);
        }
        
        $em->persist($product);
        $em->flush();

        return $product;
    }

    public function deleteProduct(int $id): void
    {
        $em = $this->emProvider->getEntityManager();
        $product = $this->entityRetrieverService->findOrFail(Product::class, $id, 'Product not found');
        $em->remove($product);
        $em->flush();
    }

    public function getAllProducts(string $host): array
    {
        $em = $this->emProvider->getEntityManager();
        $products = $em->getRepository(Product::class)->findAll();
        
        $bestsellers = array_filter($products, fn($product) => $product->isIsbestseller());
        $newArrivals = array_filter($products, fn($product) => $product->isIsnewarrival());
        $specialOffers = array_filter($products, fn($product) => $product->isIsspecialoffer());

        $bestsellersDTO = array_map(fn($product) => new ProductDetailedOutputDTO($product, $host), $bestsellers);
        $newArrivalsDTO = array_map(fn($product) => new ProductDetailedOutputDTO($product, $host), $newArrivals);
        $specialOffersDTO = array_map(fn($product) => new ProductDetailedOutputDTO($product, $host), $specialOffers);

        return [
            'bestsellers' => $bestsellersDTO,
            'newArrivals' => $newArrivalsDTO,
            'specialOffers' => $specialOffersDTO,
        ];
    }

    public function getProductsByOffer(string $offer, string $host): array
    {
        $em = $this->emProvider->getEntityManager();
        $products = $em->getRepository(Product::class)->findAll();
        $filteredProducts = [];

        switch ($offer) {
            case 'bestsellers':
                $filteredProducts = array_filter($products, fn($product) =>
                    $product->isIsbestseller() && ($product->isWeb())
                );
                break;
            case 'newarrivals':
                $filteredProducts = array_filter($products, fn($product) =>
                    $product->isIsnewarrival() && ($product->isWeb() )
                );
                break;
            case 'specialoffers':
                $filteredProducts = array_filter($products, fn($product) =>
                    $product->isIsspecialoffer() && ($product->isWeb())
                );
                break;
            case 'isfeatured':
                $filteredProducts = array_filter($products, fn($product) =>
                    $product->isIsfeatured() && ($product->isWeb() )
                );
                break;
            case 'isAccessory':
                $filteredProducts = array_filter($products, fn($product) =>
                    $product->isAccessory() && ($product->isWeb() )
                );
                break;
            default:
                return []; 
        }

        return array_map(fn($product) => new ProductDetailedOutputDTO($product, $host), $filteredProducts);
    }
}