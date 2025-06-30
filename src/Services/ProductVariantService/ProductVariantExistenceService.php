<?php
namespace App\Services\ProductVariantService;

use App\Services\TenantEntityManagerProvider;
use App\Entity\ProductVariant;

class ProductVariantExistenceService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function doesVariantExist(ProductVariant $productVariant): bool
    {
        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(ProductVariant::class);

        // Appelle la méthode complexe du repository
        $existingVariant = $repo->findExistingVariant($productVariant);

        // Retourne true si un doublon est trouvé, false sinon.
        return $existingVariant !== null;
    }
}