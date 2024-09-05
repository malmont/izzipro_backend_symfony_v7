<?php
namespace App\Services\ProductVariantService;

use App\Repository\ProductVariantRepository;
use App\Entity\ProductVariant;

class ProductVariantExistenceService
{
    private $productVariantRepository;

    public function __construct(ProductVariantRepository $productVariantRepository)
    {
        $this->productVariantRepository = $productVariantRepository;
    }

    public function doesVariantExist(ProductVariant $productVariant): bool
    {
        // Recherche d'un variant avec la même couleur et taille mais un ID différent
        $existingVariant = $this->productVariantRepository->findOneBy([
            'color' => $productVariant->getColor(),
            'size' => $productVariant->getSize(),
            'product' => $productVariant->getProduct(), // Facultatif si vous voulez vérifier dans un produit spécifique
        ]);

        // Vérifier que l'ID du produit trouvé est différent de l'ID du produit actuel
        if ($existingVariant && $existingVariant->getId() !== $productVariant->getId()) {
            return true; // Le variant existe déjà
        }

        return false; // Le variant n'existe pas
    }
}