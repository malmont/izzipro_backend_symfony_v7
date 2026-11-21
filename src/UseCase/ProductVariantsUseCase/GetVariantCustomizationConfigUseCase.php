<?php

namespace App\UseCase\ProductVariantsUseCase;

use App\Entity\ProductVariant;
use App\Services\ProductVariantService\CustomizationFactoryService;
use App\Dto\VariantConfigResponseDto;
use App\Services\TenantEntityManagerProvider; 
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GetVariantCustomizationConfigUseCase
{
    private TenantEntityManagerProvider $emProvider;
    private CustomizationFactoryService $dtoFactory;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        CustomizationFactoryService $dtoFactory,
        private readonly ?\App\Services\Storefront\PublicProductVisibility $visibility = null, // lot FICHES-HORS-LIGNE (21/11/2026)
    ) {
        $this->emProvider = $emProvider;
        $this->dtoFactory = $dtoFactory;
    }

    public function execute(int $variantId): VariantConfigResponseDto
    {
  
        $tenantEm = $this->emProvider->getEntityManager();

        $variant = $tenantEm->getRepository(ProductVariant::class)->find($variantId);

        if (!$variant || ($variant->getProduct() !== null && $this->visibility?->hidden((int) $variant->getProduct()->getId()))) {
            throw new NotFoundHttpException("Variant introuvable (ID: $variantId)");
        }

        return $this->dtoFactory->createConfigForVariant($variant);
    }
}