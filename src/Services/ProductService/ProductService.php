<?php

namespace App\Services\ProductService;

use App\Entity\Product;
use App\Entity\Commande;
use App\Entity\Categories;
use App\Entity\Style;
use App\Dto\ProductInputDTO;
use App\Dto\ProductDetailedOutputDTO;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
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
        EntityRetrieverService $entityRetrieverService,
        private readonly TenantCurrencyProvider $currency,
        private readonly ?\App\Services\CommandeService\CommandeLineService $lines = null, // lignes d'achat (lot L0 de la gestion, 10/11/2026)
    ) {
        $em = $emProvider->getEntityManager();
        $this->repository = $em->getRepository(Product::class);
        $this->entityRetrieverService = $entityRetrieverService;
        $this->emProvider = $emProvider;
    }

    public function getProductsByCommande(Commande $commande, string $host, string $locale = 'fr'): array
    {
        // lot L0 (10/11/2026) : produits distincts des lignes d'achat de la commande (un article peut être acheté dans plusieurs commandes)
        $ids = $this->lines !== null ? $this->emProvider->getEntityManager()->getRepository(\App\Entity\CommandeLine::class)->productIdsOf($commande) : null;
        $products = $ids === null ? $commande->getProducts() : new \Doctrine\Common\Collections\ArrayCollection($ids === [] ? [] : $this->emProvider->getEntityManager()->getRepository(Product::class)->findBy(['id' => $ids], ['id' => 'ASC']));
        $currency = $this->currency->code();
        // langue demandée (?locale=) au lieu de « fr » figé (lot G3, 09/11/2026)
        return array_map(fn($product) => new ProductDetailedOutputDTO($product, $host, $locale, $currency), $products->toArray());
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
        $product->setCommandeAttachedAt(new \DateTimeImmutable()); // lot P2 périodes (10/11/2026)
        if ($inputDTO->quantity !== null) {
            $product->setQuantity($inputDTO->quantity); // quantité facultative (lot G4, 09/11/2026) ; sinon celle des variantes
        }

        // lot G4 (09/11/2026) : références inconnues → 404 à code (avant : 500 avec le message)
        if ($inputDTO->styleId) {
            $style = $em->getRepository(Style::class)->find($inputDTO->styleId);
            if (!$style) {
                throw new \App\Gestion\Services\GestionConflictException('STYLE_NOT_FOUND', 'Style inconnu : ' . $inputDTO->styleId, 404);
            }
            $product->setStyle($style);
        }

        foreach ($inputDTO->categoryIds as $categoryId) {
            $category = $em->getRepository(Categories::class)->find((int) $categoryId);
            if (!$category) {
                throw new \App\Gestion\Services\GestionConflictException('CATEGORY_NOT_FOUND', 'Catégorie inconnue : ' . $categoryId, 404);
            }
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
        // ligne d'achat de l'article créé dans la commande (lot L0) : quantité = stock de l'article, coût = prix d'achat saisi (cents) ou inconnu
        $this->lines?->upsert($commande, $product, null, (int) ($product->getQuantity() ?? 0), $inputDTO->purchasePrice !== null ? (int) round((float) $inputDTO->purchasePrice) : null, false, \App\Entity\CommandeLine::SOURCE_MANUAL);

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
        return new ProductDetailedOutputDTO($product, $host, $locale, $this->currency->code());
    }

    public function getProductBySlug(string $slug, string $host, string $locale = 'fr'): ProductDetailedOutputDTO
    {
        $product = $this->repository->findBySlugAndLocale($slug, $locale);

        if (!$product) {
            throw new NotFoundHttpException('Product not found for slug: ' . $slug);
        }
        return new ProductDetailedOutputDTO($product, $host, $locale, $this->currency->code());
    }

    public function getAllProducts(string $host, string $locale = 'fr'): array
    {
        $bestsellers   = $this->repository->findTranslatedByCriteria($locale, ['isbestseller' => true, 'isWeb' => true]);
        $newArrivals   = $this->repository->findTranslatedByCriteria($locale, ['isnewarrival' => true, 'isWeb' => true]);
        $specialOffers = $this->repository->findTranslatedByCriteria($locale, ['isspecialoffer' => true, 'isWeb' => true]);

        return [
            'bestsellers'   => array_map(fn($p) => new ProductDetailedOutputDTO($p, $host, $locale, $this->currency->code()), $bestsellers),
            'newArrivals'   => array_map(fn($p) => new ProductDetailedOutputDTO($p, $host, $locale, $this->currency->code()), $newArrivals),
            'specialOffers' => array_map(fn($p) => new ProductDetailedOutputDTO($p, $host, $locale, $this->currency->code()), $specialOffers),
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
        return array_map(fn($product) => new ProductDetailedOutputDTO($product, $host, $locale, $this->currency->code()), $products);
    }

    public function getLandingPageProducts(string $host, string $locale = 'fr'): array
    {
        $products = $this->repository->findTranslatedByCriteria($locale, ['isLandingPage' => true]);
        return array_map(fn($product) => new ProductDetailedOutputDTO($product, $host, $locale, $this->currency->code()), $products);
    }
}
