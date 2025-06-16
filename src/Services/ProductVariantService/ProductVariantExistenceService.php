<?php
namespace App\Services\ProductVariantService;

use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider
use App\Entity\ProductVariant;

class ProductVariantExistenceService
{
    // MODIFICATION 1 : Le service ne dépend plus que du provider
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function doesVariantExist(ProductVariant $productVariant): bool
    {
        // MODIFICATION 2 : On récupère l'EM et le repository ici
        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(ProductVariant::class);

        // On utilise le repository obtenu depuis l'EM du tenant
        $existingVariant = $repo->findOneBy([
            'color' => $productVariant->getColor(),
            'size' => $productVariant->getSize(),
            'product' => $productVariant->getProduct(),
        ]);

        // Le reste de votre logique est inchangée
        if ($existingVariant && $existingVariant->getId() !== $productVariant->getId()) {
            return true;
        }

        return false;
    }
}