<?php
namespace App\Services\ProductService;

use App\Entity\Product;
use App\Entity\Commande;
use App\Entity\Categories;
use App\Entity\Style;
use App\Dto\ProductInputDTO;
use App\Dto\ProductOutputDTO;
use App\Dto\ProductDetailedOutputDTO;
use App\Services\EntityRetrieverService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Cocur\Slugify\Slugify;

class ProductService
{
    private $entityManager;
    private $entityRetrieverService;

    public function __construct(EntityManagerInterface $entityManager, EntityRetrieverService $entityRetrieverService)
    {
        $this->entityManager = $entityManager;
        $this->entityRetrieverService = $entityRetrieverService;
    }

    public function getProductsByCommande(Commande $commande, string $host): array
    {
        $products = $commande->getProducts();
        return array_map(fn($product) => new ProductDetailedOutputDTO($product, $host), $products->toArray());
    }

    public function createProductByCommande(Commande $commande, ProductInputDTO $inputDTO, Request $request, string $uploadDir): Product
    {
        $product = new Product();
        $product->setName($inputDTO->name);
        $product->setDescription($inputDTO->description);
        $product->setPurchasePrice($inputDTO->purchasePrice);
        $product->setCoefficientMultiplier($inputDTO->coefficientMultiplier);
        $product->setCommande($commande);

        // Ajout du style
        if ($inputDTO->styleId) {
            $style = $this->entityRetrieverService->findOrFail(Style::class, $inputDTO->styleId, 'Style not found');
            $product->setStyle($style);
        }

        // Ajout des catégories
        foreach ($inputDTO->categoryIds as $categoryId) {
            $category = $this->entityRetrieverService->findOrFail(Categories::class, $categoryId, "Category not found for ID: $categoryId");
            $product->addCategory($category);
        }

        // Génération du slug
        $slugify = new Slugify();
        $product->setSlug($slugify->slugify($inputDTO->name));

        // Gestion de l'image
        $imageFile = $request->files->get('image');
        if ($imageFile) {
            $newFilename = uniqid() . '.' . $imageFile->guessExtension();
            $imageFile->move($uploadDir, $newFilename);  // Utilisation de l'uploadDir passé dans le Use Case
            $product->setImage($newFilename);
        }

        // Persister le produit
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    public function deleteProduct(int $id): void
    {
        $product = $this->entityRetrieverService->findOrFail(Product::class, $id, 'Product not found');
        $this->entityManager->remove($product);
        $this->entityManager->flush();
    }

    public function getAllProducts(string $host): array
    {

        $products = $this->entityManager->getRepository(Product::class)->findAll();
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
        $products = $this->entityManager->getRepository(Product::class)->findAll();

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
