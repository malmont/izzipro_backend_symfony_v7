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

    public function getProductById(int $id, string $host): ProductDetailedOutputDTO
    {
        $em = $this->emProvider->getEntityManager();
        $productRepo = $em->getRepository(Product::class);

        $product = $productRepo->findOneBy(['id' => $id]);

        if (!$product) {
            throw new NotFoundHttpException('Product not found for ID: ' . $id);
        }

        return new ProductDetailedOutputDTO($product, $host);
    }


    public function getAllProducts(string $host): array
    {
        $em = $this->emProvider->getEntityManager();
        $productRepo = $em->getRepository(Product::class);
        
        $bestsellers = $productRepo->findBy(['isbestseller' => true, 'isWeb' => true]);
        $newArrivals = $productRepo->findBy(['isnewarrival' => true, 'isWeb' => true]);
        $specialOffers = $productRepo->findBy(['isspecialoffer' => true, 'isWeb' => true]);

        return [
            'bestsellers' => array_map(fn($p) => new ProductDetailedOutputDTO($p, $host), $bestsellers),
            'newArrivals' => array_map(fn($p) => new ProductDetailedOutputDTO($p, $host), $newArrivals),
            'specialOffers' => array_map(fn($p) => new ProductDetailedOutputDTO($p, $host), $specialOffers),
        ];
    }

    public function getProductsByOffer(string $offer, string $host): array
    {
        $em = $this->emProvider->getEntityManager();
        
        $criteria = ['isWeb' => true];
        switch ($offer) {
            case 'bestsellers':
                $criteria['isbestseller'] = true;
                break;
            case 'newarrivals':
                $criteria['isnewarrival'] = true;
                break;
            case 'specialoffers':
                $criteria['isspecialoffer'] = true;
                break;
            case 'isfeatured':
                $criteria['isfeatured'] = true;
                break;
            case 'isAccessory':
                $criteria['isAccessory'] = true;
                break;
            default:
                return []; 
        }

        $products = $em->getRepository(Product::class)->findBy($criteria);

        return array_map(fn($product) => new ProductDetailedOutputDTO($product, $host), $products);
    }

    public function getLandingPageProducts(string $host): array
    {
        $em = $this->emProvider->getEntityManager();
        
        $products = $em->getRepository(Product::class)->findBy([
            'isLandingPage' => true,
        ]);
    return array_map(fn($product) => new ProductDetailedOutputDTO($product, $host), $products);
    }
}
