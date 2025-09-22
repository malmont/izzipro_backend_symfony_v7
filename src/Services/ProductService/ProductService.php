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
use App\Repository\ProductRepository;

class ProductService
{
    private ProductRepository $repository;
    private TenantEntityManagerProvider $emProvider;
    private EntityRetrieverService $entityRetrieverService;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        EntityRetrieverService $entityRetrieverService
    ) {
        $em = $emProvider->getEntityManager();
        $this->repository = $em->getRepository(Product::class);
        $this->entityRetrieverService = $entityRetrieverService;
        $this->emProvider = $emProvider;
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

    public function getProductById(int $id, string $host, string $locale = 'fr'): ProductDetailedOutputDTO
    {
        $product = $this->repository->findByIdAndLocale($id, $locale);

        if (!$product) {
            throw new NotFoundHttpException('Product not found for ID: ' . $id);
        }
        return new ProductDetailedOutputDTO($product, $host, $locale);
    }

    public function getAllProducts(string $host, string $locale = 'fr'): array
    {
        $bestsellers   = $this->repository->findTranslatedByCriteria($locale, ['isbestseller' => true, 'isWeb' => true]);
        $newArrivals   = $this->repository->findTranslatedByCriteria($locale, ['isnewarrival' => true, 'isWeb' => true]);
        $specialOffers = $this->repository->findTranslatedByCriteria($locale, ['isspecialoffer' => true, 'isWeb' => true]);

        return [
            'bestsellers'   => array_map(fn($p) => new ProductDetailedOutputDTO($p, $host, $locale), $bestsellers),
            'newArrivals'   => array_map(fn($p) => new ProductDetailedOutputDTO($p, $host, $locale), $newArrivals),
            'specialOffers' => array_map(fn($p) => new ProductDetailedOutputDTO($p, $host, $locale), $specialOffers),
        ];
    }

    public function getProductsByOffer(string $offer, string $host, string $locale = 'fr'): array
    {
        $criteria = ['isWeb' => true];
        $dbFieldMap = [
            'bestsellers'   => 'isbestseller',
            'newarrivals'   => 'isnewarrival',
            'specialoffers' => 'isspecialoffer',
            'isfeatured'    => 'isfeatured',
            'isAccessory'   => 'isAccessory'
        ];
        if (!isset($dbFieldMap[$offer])) {
            return []; 
        }
        $criteria[$dbFieldMap[$offer]] = true;
        $products = $this->repository->findTranslatedByCriteria($locale, $criteria);
        return array_map(fn($product) => new ProductDetailedOutputDTO($product, $host, $locale), $products);
    }

    public function getLandingPageProducts(string $host, string $locale = 'fr'): array
    {
        $products = $this->repository->findTranslatedByCriteria($locale, ['isLandingPage' => true]);
        return array_map(fn($product) => new ProductDetailedOutputDTO($product, $host, $locale), $products);
    }
}
