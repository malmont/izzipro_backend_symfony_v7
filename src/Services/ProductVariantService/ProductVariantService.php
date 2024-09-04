<?php
namespace App\Services\ProductVariantService;

use App\Entity\Product;
use App\Entity\Color;
use App\Entity\Size;
use App\Entity\ProductVariant;
use App\Dto\ProductVariantInputDTO;
use App\Dto\ProductVariantDTO;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\EntityRetrieverService;

class ProductVariantService
{
    private $entityManager;
    private $updateStockAndInventoryUseCase;
    private $entityRetrieverService;

    public function __construct(
        EntityManagerInterface $entityManager,
        UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase,
        EntityRetrieverService $entityRetrieverService
    ) {
        $this->entityManager = $entityManager;
        $this->updateStockAndInventoryUseCase = $updateStockAndInventoryUseCase;
        $this->entityRetrieverService = $entityRetrieverService;
    }

    public function getProductVariants(Product $product): array
    {
        $variants = $product->getVariants();
        $variantsDTOs = [];

        foreach ($variants as $variant) {
            $variantsDTOs[] = ProductVariantDTO::fromEntity($variant);
        }

        return $variantsDTOs;
    }

    public function createProductVariant(Product $product, ProductVariantInputDTO $inputDTO): ProductVariantDTO
    {
        $variant = new ProductVariant();
        $variant->setProduct($product);
        $variant->setStockQuantity(0);

        // Utilisation du service EntityRetrieverService pour vérifier et obtenir la couleur
        if ($inputDTO->colorId) {
            $color = $this->entityRetrieverService->findOrFail(Color::class, $inputDTO->colorId, 'Color not found');
            $variant->setColor($color);
        }

        // Utilisation du service EntityRetrieverService pour vérifier et obtenir la taille
        if ($inputDTO->sizeId) {
            $size = $this->entityRetrieverService->findOrFail(Size::class, $inputDTO->sizeId, 'Size not found');
            $variant->setSize($size);
        }
 
        // Enregistrer la variante
        $this->entityManager->persist($variant);
        

        // Mise à jour du stock et création du mouvement d'inventaire
        $this->updateStockAndInventoryUseCase->execute($variant, $inputDTO->stockQuantity,true);

        $this->entityManager->flush();
        return ProductVariantDTO::fromEntity($variant);
    }

    public function deleteProductVariant(ProductVariant $variant): void
    {
        // Supprimer la variante et créer un mouvement de stock
        $this->updateStockAndInventoryUseCase->execute($variant, $variant->getStockQuantity());

        $this->entityManager->remove($variant);
        $this->entityManager->flush();
    }
}
