<?php

namespace App\Services\ProductVariantService;

use App\Entity\ProductVariant;
use App\Entity\ProductOption;
use App\Dto\VariantConfigResponseDto;
use App\Dto\CustomizationGroupDto;
use App\Dto\CustomizationValueDto;
use App\Dto\CustomizationCombinationDto;
use App\Services\TenantEntityManagerProvider; 
use Symfony\Component\HttpFoundation\RequestStack;

class CustomizationFactoryService
{
   private TenantEntityManagerProvider $emProvider;
    private RequestStack $requestStack;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        RequestStack $requestStack
    ) {
        $this->emProvider = $emProvider;
        $this->requestStack = $requestStack;
    }

    public function createConfigForVariant(ProductVariant $variant): VariantConfigResponseDto
    {
        $product = $variant->getProduct();
        $basePrice = $product->getPrice(); 

        $request = $this->requestStack->getCurrentRequest();
        $host = $request ? $request->getSchemeAndHttpHost() : '';

        $uniqueOptions = [];
        $uniqueValues = [];
        $combinationsDtos = [];

        foreach ($variant->getProductCustomizationImages() as $customImage) {
            
            $currentCombinationOptionIds = [];
            $combinationPriceDelta = 0.0;

            foreach ($customImage->getOptionValues() as $value) {
                $group = $value->getProductOption();
                if (!$group) continue;

                $uniqueOptions[$group->getId()] = $group;
                $uniqueValues[$group->getId()][$value->getId()] = $value;

                $currentCombinationOptionIds[] = $value->getId();
                $combinationPriceDelta += ($value->getPriceDelta() ?? 0.0) * 100;
            }

            sort($currentCombinationOptionIds);

            $combinationsDtos[] = CustomizationCombinationDto::fromEntity(
                $customImage,
                $basePrice,
                $currentCombinationOptionIds,
                $combinationPriceDelta,
                $host
            );
        }

        $optionsDtos = [];
        ksort($uniqueOptions); 

        foreach ($uniqueOptions as $groupId => $group) {
            $valuesDtos = [];
            $groupValues = $uniqueValues[$groupId] ?? [];
            
            foreach ($groupValues as $val) {
                $valuesDtos[] = CustomizationValueDto::fromEntity($val, $host);
            }
            
            usort($valuesDtos, fn($a, $b) => strcmp($a->name, $b->name));

            $optionsDtos[] = new CustomizationGroupDto(
                id: $group->getId(),
                code: $group->getCode() ?? 'opt_' . $group->getId(),
                name: $group->getName(),
                values: $valuesDtos
            );
        }

        $baseImageUrl = null;
        if ($product->getImage()) {
            $baseImageUrl = str_starts_with($product->getImage(), 'http') 
                ? $product->getImage() 
                : $host . '/assets/uploads/products/' . $product->getImage(); 
        }

        return new VariantConfigResponseDto(
            variantId: $variant->getId(),
            productName: $product->getName(),
            variantName: ($variant->getColor()?->getName() ?? '') . ' ' . ($variant->getSize()?->getName() ?? ''),
            basePrice: $basePrice,
            baseImage: $baseImageUrl, // URL absolue
            options: $optionsDtos,
            combinations: $combinationsDtos
        );
    }
}