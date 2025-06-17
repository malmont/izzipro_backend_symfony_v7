<?php
namespace App\Services\ProductVariantService;

use App\Entity\Product;
use App\Entity\Color;
use App\Entity\Size;
use App\Entity\ProductVariant;
use App\Dto\ProductVariantInputDTO;
use App\Dto\ProductVariantDTO;
use App\UseCase\OrderUseCase\UpdateStockAndInventoryUseCase;
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider
use App\Services\EntityRetrieverService;

class ProductVariantService
{
    // MODIFICATION 1 : Remplacement de l'EntityManager par le Provider
    private TenantEntityManagerProvider $emProvider;
    private UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase;
    private EntityRetrieverService $entityRetrieverService;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        UpdateStockAndInventoryUseCase $updateStockAndInventoryUseCase,
        EntityRetrieverService $entityRetrieverService
    ) {
        $this->emProvider = $emProvider;
        $this->updateStockAndInventoryUseCase = $updateStockAndInventoryUseCase;
        $this->entityRetrieverService = $entityRetrieverService;
    }

    /**
     * INCHANGÉ : Cette méthode ne touche pas à la base de données.
     * Elle lit simplement une propriété d'un objet déjà chargé.
     */
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
        // MODIFICATION 2 : On récupère l'EM du tenant ici
        $em = $this->emProvider->getEntityManager();

        $variant = new ProductVariant();
        $variant->setProduct($product);
        $variant->setStockQuantity(0);

        // INCHANGÉ : On conserve l'utilisation de votre service
        if ($inputDTO->colorId) {
            $color = $this->entityRetrieverService->findOrFail(Color::class, $inputDTO->colorId, 'Color not found');
            $variant->setColor($color);
        }

        if ($inputDTO->sizeId) {
            $size = $this->entityRetrieverService->findOrFail(Size::class, $inputDTO->sizeId, 'Size not found');
            $variant->setSize($size);
        }
 

        $em->persist($variant);
        
        $this->updateStockAndInventoryUseCase->execute($variant, $inputDTO->stockQuantity, true);

        // On utilise l'EM du tenant pour la sauvegarde finale
        $em->flush();
        return ProductVariantDTO::fromEntity($variant);
    }

    public function deleteProductVariant(ProductVariant $variant): void
    {
        $em = $this->emProvider->getEntityManager();
        
        // On appelle le UseCase avant de supprimer
        $this->updateStockAndInventoryUseCase->execute($variant, $variant->getStockQuantity());
        $em->remove($variant);
        
        $em->flush();
    }
}