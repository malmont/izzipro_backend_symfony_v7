<?php

namespace App\Services\ProductVariantService;

use App\Entity\ProductVariant;
use App\Entity\ProductOption;
use App\Dto\VariantConfigResponseDto;
use App\Dto\CustomizationGroupDto;
use App\Dto\CustomizationValueDto;
use App\Dto\CustomizationCombinationDto;
use App\Services\TenantEntityManagerProvider; 
use App\Services\MediaUrlResolver;
use Symfony\Component\HttpFoundation\RequestStack;

class CustomizationFactoryService
{
    private TenantEntityManagerProvider $emProvider;
    private RequestStack $requestStack;
    private MediaUrlResolver $mediaUrlResolver;

    private ?CustomizationMediaResolver $mediaResolver = null;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setMediaResolver(CustomizationMediaResolver $mediaResolver): void
    {
        $this->mediaResolver = $mediaResolver;
    }

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        RequestStack $requestStack,
        MediaUrlResolver $mediaUrlResolver
    ) {
        $this->emProvider = $emProvider;
        $this->requestStack = $requestStack;
        $this->mediaUrlResolver = $mediaUrlResolver;
    }

    public function createConfigForVariant(ProductVariant $variant): VariantConfigResponseDto
    {
        $product = $variant->getProduct();
        $basePrice = $product->getPrice(); 

        $request = $this->requestStack->getCurrentRequest();
        $rawHost = $request ? $request->getSchemeAndHttpHost() : '';
        $host = $this->mediaUrlResolver->getPublicHost($rawHost);

        $uniqueOptions = [];
        $uniqueValues = [];
        $combinationsDtos = [];

        // Deux combinaisons pour le même ensemble d'options (doublon) : une seule est rendue, celle dont le fichier existe,
        // sinon la plus ancienne (09/10/2026 ; l'enregistrement de doublons est désormais refusé)
        $kept = [];
        foreach ($variant->getProductCustomizationImages() as $customImage) {
            $ids = array_map(fn ($v) => $v->getId(), $customImage->getOptionValues()->toArray());
            sort($ids);
            $key = implode(',', $ids);
            $current = $kept[$key] ?? null;
            $hasFile = $this->mediaResolver->combinationDir($customImage->getImagePath()) !== null;
            if ($current === null || ($hasFile && $this->mediaResolver->combinationDir($current->getImagePath()) === null)
                || ($hasFile === ($this->mediaResolver->combinationDir($current->getImagePath()) !== null) && $customImage->getId() < $current->getId())) {
                $kept[$key] = $customImage;
            }
        }

        foreach ($kept as $customImage) {
            
            $currentCombinationOptionIds = [];
            $combinationPriceDelta = (float) $customImage->priceDeltaCents(); // même calcul que le devis du panier

            foreach ($customImage->getOptionValues() as $value) {
                $group = $value->getProductOption();
                if (!$group) continue;

                $uniqueOptions[$group->getId()] = $group;
                $uniqueValues[$group->getId()][$value->getId()] = $value;

                $currentCombinationOptionIds[] = $value->getId();
            }

            sort($currentCombinationOptionIds);

            $combinationsDtos[] = CustomizationCombinationDto::fromEntity(
                $customImage,
                $basePrice,
                $currentCombinationOptionIds,
                $combinationPriceDelta,
                $this->mediaResolver->combinationUrl($customImage->getImagePath(), $rawHost)
            );
        }

        $optionsDtos = [];
        ksort($uniqueOptions); 

        foreach ($uniqueOptions as $groupId => $group) {
            $valuesDtos = [];
            $groupValues = $uniqueValues[$groupId] ?? [];
            
            foreach ($groupValues as $val) {
                $valuesDtos[] = CustomizationValueDto::fromEntity($val, $this->mediaResolver->optionIconUrl($val->getImagePreview(), $rawHost));
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