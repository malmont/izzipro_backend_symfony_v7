<?php

namespace App\Dto;
use App\Entity\ProductCustomizationImage;

class CustomizationCombinationDto
{
    public function __construct(
        public int $id,
        public array $optionIds,
        public ?string $imageUrl,
        public int $stock,
        public float $totalPrice
    ) {}

    /** @param ?string $imageUrl adresse résolue par CustomizationMediaResolver (null : fichier introuvable) */
    public static function fromEntity(ProductCustomizationImage $image, float $basePrice, array $optionIds, float $optionPriceDelta, ?string $imageUrl): self
    {
        return new self($image->getId(), $optionIds, $imageUrl, $image->getNumberOfPieces() ?? 0, $basePrice + $optionPriceDelta);
    }
}
